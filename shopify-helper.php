#!/usr/bin/php
<?php
class ShopifyImportExport
{
    public function __construct( $export_url, $import_url )
    {
        $this->export_url = $export_url;
        $this->import_url = $import_url;
    }

    protected function call($url, $method="GET", $params=[])
    {

        preg_match('#https://(.*?)@(.*)$#', $url, $matches);
        $userpass = $matches[1];
        $url = 'https://'.$matches[2];

        $ch = curl_init();
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            // CURLOPT_VERBOSE        => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $userpass,
            CURLOPT_URL            => $url
        ];
        switch (strtoupper($method)) {
            case 'GET':
                if( !empty( $params ) ){
                    $url.='?'.http_build_query($params);
                }
                $options[CURLOPT_URL] = $url;
                break;

            case 'POST':
                $options[CURLOPT_POST] = true;
                //$options[CURLOPT_VERBOSE] = true;
                if ($params) {
                    if (!is_string($params)) {
                        $params = json_encode($params);
                    }

                    $options[CURLOPT_POSTFIELDS] = $params;
                    $options[CURLOPT_HTTPHEADER] = [
                        'Content-Type: application/json',
                        'Content-Length: '.strlen($params)
                    ];
                }
                break;
            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = "PUT";
                //$options[CURLOPT_VERBOSE] = true;
                if ($params) {
                    if (!is_string($params)) {
                        $params = json_encode($params);
                    }

                    $options[CURLOPT_POSTFIELDS] = $params;
                    $options[CURLOPT_HTTPHEADER] = [
                        'Content-Type: application/json',
                        'Content-Length: '.strlen($params)
                    ];
                }
                break;
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = "DELETE";
                if ($params && !empty($params)) {
                    if (!is_string($params)) {
                        $params = json_encode($params);
                    }

                    $options[CURLOPT_POSTFIELDS] = $params;
                    $options[CURLOPT_HTTPHEADER] = [
                        'Content-Type: application/json',
                        'Content-Length: '.strlen($params)
                    ];
                }
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        curl_setopt_array($ch, $options);

        $json = curl_exec($ch);

        if( ($err = curl_error($ch)) ){
            print_r( $err );
            print_r( curl_getinfo( $ch ) );
        }


        $response = json_decode($json);
        if( !$response || isset( $response->errors ) ){
            print_r( curl_getinfo( $ch ) );

        }
        curl_close( $ch );
        return $response;
    }

    public function export($endpoint, $method="GET", $params=[])
    {
        return $this->call($this->export_url.$endpoint, $method, $params);
    }

    public function import($endpoint, $method="GET", $params=[])
    {
        return $this->call($this->import_url.$endpoint, $method, $params);
    }

    public function test()
    {

        $exportSuccess = !empty($this->export('/admin/themes.json'));
        $importSuccess = !empty($this->import('/admin/themes.json'));

        echo ($exportSuccess ? '[Success]' : '[Fail]' )." connecting to export server\n";
        echo ($importSuccess ? '[Success]' : '[Fail]' )." connecting to import server\n";

    }

    public function getInfo(){

        $source = $this->export( '/admin/shop.json' );
        $target = $this->import( '/admin/shop.json' );

        echo "EXPORT: {$source->shop->domain}\n";
        echo "IMPORT: {$target->shop->domain}\n";

    }

    public function importPages()
    {
        $response = $this->export('/admin/pages.json');
        $export = [];
        foreach ($response->pages as $i => $page) {
            //if( $i > 0 ) break;
            // Get the metadata for this page
            $id = $page->id;
            $metafields = $this->export("/admin/pages/$id/metafields.json");
            $page->metafields = [];
            foreach ($metafields->metafields as $metafield) {
                unset($metafield->id);
                unset($metafield->owner_id);
                unset($metafield->created_at);
                unset($metafield->updated_at);
                unset($metafield->owner_resource);
                $page->metafields[] = $metafield;
            }

            // unset fields
            unset($page->id);
            unset($page->author);
            unset($page->created_at);
            unset($page->updated_at);
            unset($page->published_at);
            unset($page->shop_id);

            $export[] = $page;
        }
        foreach ($export as $page) {
            echo "[Import] {$page->title}\n";
            $this->import('/admin/pages.json', 'POST', ['page'=>$page]);
        }
    }



    public function importBlogs()
    {
        $target = $this->import('/admin/blogs.json', 'GET', ['handle'=>'news']);
        if (!count($target->blogs)) {
            return;
        }
        $target = $target->blogs[0];
        $source = $this->export('/admin/blogs.json', 'GET', ['handle'=>'news']);


        foreach ($source->blogs as $blog) {
            $articles = $this->export('/admin/blogs/'.$blog->id.'/articles.json', 'GET', [
                'since' => 0
            ]);
            foreach ($articles->articles as $article) {

                // unset all instance specific fields
                $metafields = $this->export("/admin/blogs/{$blog->id}/articles/{$article->id}/metafields.json")->metafields;

                foreach (['id','blog_id','author','user_id'] as $key) {
                    unset($article->$key);
                }
                $article->metafields = $metafields;
                foreach ($article->metafields as $i => $metafield) {
                    foreach (['id','owner_id','owner_resource','created_at','updated_at'] as $key) {
                        unset($article->metafields[$i]->$key);
                    }
                }
                echo "[Import] {$article->title}\n";
                $this->import('/admin/blogs/'.$target->id.'/articles.json', 'POST', ['article'=>$article]);
            }
        };
    }

    public function syncArticles( $blog_handle='news')
    {
        $target = $this->import('/admin/blogs.json', 'GET', ['handle'=>$blog_handle]);
        if (!count($target->blogs)) {
            return;
        }
        $target = $target->blogs[0];

        $source = $this->export('/admin/blogs.json', 'GET', ['handle'=>$blog_handle]);

        // create an index of the existing articles on the target blog
        $existing = [];
        $last_id = 0;
        while( ($articles = $this->import('/admin/blogs/'.$target->id.'/articles.json', 'GET', [
            'since_id' => $last_id
        ])) && $articles && isset( $articles->articles ) && count( $articles->articles ) ){
            foreach( $articles->articles as $article ){
                $existing[$article->handle] = $article;
                $last_id = $article->id;
            }
        }


        // Go through the source articles
        $last_id = 0;
        $count = 0;
        while( ($articles = $this->export('/admin/blogs/'.$target->id.'/articles.json', 'GET', [
            'since_id' => $last_id
        ])) && $articles && isset( $articles->articles ) && count( $articles->articles ) ){
            foreach( $articles->articles as $article ){

                $last_id = $article->id;

                $handle = $article->handle;
                $action = 'CREATE';

                // check to see if the handle exists
                if( isset( $existing[$handle] ) ){
                    // check to see if source is newer
                    if( $article->updated_at > $existing[$handle]->updated_at ){
                        $action = 'UPDATE';
                    }
                    else {
                        //$action = 'IGNORE';
                        $action = 'UPDATE';
                    }
                }

                switch( $action ){

                    case 'CREATE':
                        $article = $this->cleanArticle( $article );
                        echo "CREATING {$article->title}\n";
                        $response = $this->import("/admin/blogs/{$target->id}/articles.json", 'POST', ['article'=>$article]);
                        break;

                    case 'UPDATE':
                        $article = $this->cleanArticle( $article, $existing[$article->handle] );
                        echo "UPDATING {$article->title}\n";
                        $import_id = $existing[$article->handle]->id;
                        $article->id = $import_id;
                        $response = $this->import("/admin/blogs/{$target->id}/articles/{$import_id}.json", 'PUT', ['article'=>$article]);
                        break;

                    case 'IGNORE':
                        echo "IGNORING {$article->title}\n";
                }
            }
        }
    }

    protected function cleanArticle( $article, $existing=null )
    {
        $metafields = $this->export("/admin/blogs/{$article->blog_id}/articles/{$article->id}/metafields.json")->metafields;
        $clean = ['id', 'blog_id', 'author','user_id','updated_at'];
        foreach ($clean as $key) {
            unset($article->$key);
        }
        if( $existing ){
            $article->id = $existing->id;
        }
        $article->metafields = $metafields;
        $cleanmeta = ['id','owner_id','owner_resource','created_at','updated_at'];
        $existing_meta = [];
        if( $existing ){
            $existing_metafields = $this->import("/admin/blogs/{$existing->blog_id}/articles/{$existing->id}/metafields.json")->metafields;
            if( isset( $existing_metafields ) ){
                foreach( $existing_metafields as $meta ){
                    if( !isset( $existing_meta[$meta->namespace] ) ){
                        $existing_meta[$meta->namespace] = [];
                    }
                    $existing_meta[$meta->namespace][$meta->key] = $meta->id;
                }
            }
        }
        foreach ($article->metafields as $i => $metafield) {
            foreach ($cleanmeta as $key) {
                unset($article->metafields[$i]->$key);
            }
            if( isset( $existing_meta[$metafield->namespace] ) && isset( $existing_meta[$metafield->namespace][$metafield->key]) ){
                $article->metafields[$i]->id = $existing_meta[$metafield->namespace][$metafield->key];
            }

        }
        $article->metafields = array_values((array)$article->metafields);

        return $article;
    }


	protected function make_safe_for_utf8_use($string) {

	    $encoding = mb_detect_encoding($string, "UTF-8,ISO-8859-1,WINDOWS-1252");

	    if ($encoding != 'UTF-8') {
	        return iconv($encoding, 'UTF-8//TRANSLIT', $string);
	    } else {
	        return $string;
	    }
	}

    public function createCommentsExportXML()
    {
        $since = 1;
        $articles = [];
        while (($comments = $this->export('/admin/comments.json?since_id='.$since)) && count($comments->comments)) {
            foreach ($comments->comments as $comment) {
                if (!isset($articles[ $comment->article_id ])) {
                    $article = $this->export("/admin/blogs/{$comment->blog_id}/articles/{$comment->article_id}.json");
                    if ($article &&  $article->article) {
                        $articles[ $comment->article_id ] = $article->article;
                        $articles[ $comment->article_id ]->comments = [];
                    }
                }
                $articles[ $comment->article_id ]->comments[] = $comment;
                $since = $comment->id;
            }
        }
        ob_start(); ?>
<?php echo '<?' ?>xml version="1.0" encoding="UTF-8"<?php echo '?>'."\n"; ?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dsq="http://www.disqus.com/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.0/"
>
  <channel>
<?php foreach ($articles as $article) {
    $time = @strtotime($article->published_at);
    $article->post_date_gmt = gmdate('Y-m-d H:i:s', $time);
?>
    <item>
      <!-- title of article -->
      <title><?php echo htmlentities($article->title); ?></title>
      <!-- absolute URI to article -->
      <link>https://getrocketbook.com/blogs/news/<?php echo $article->handle; ?></link>
      <!-- value used within disqus_identifier; usually internal identifier of article -->
      <dsq:thread_identifier><?php echo $article->id ?></dsq:thread_identifier>
      <!-- creation date of thread (article), in GMT. Must be YYYY-MM-DD HH:MM:SS 24-hour format. -->
      <wp:post_date_gmt><?php echo $article->post_date_gmt; ?></wp:post_date_gmt>
      <!-- open/closed values are acceptable -->
      <wp:comment_status>open</wp:comment_status>
<?php foreach ($article->comments as $comment) { ?>
      <wp:comment>
        <!-- internal id of comment -->
        <wp:comment_id><?php echo $comment->id; ?></wp:comment_id>
        <!-- author display name -->
        <wp:comment_author><?php echo $comment->author; ?></wp:comment_author>
        <!-- author email address -->
        <wp:comment_author_email><?php echo $comment->email; ?></wp:comment_author_email>
        <!-- author ip address -->
        <wp:comment_author_IP><?php echo $comment->ip; ?></wp:comment_author_IP>
        <!-- comment datetime, in GMT. Must be YYYY-MM-DD HH:MM:SS 24-hour format. -->
        <wp:comment_date_gmt><?php echo gmdate('Y-m-d H:i:s', @strtotime($comment->created_at)); ?></wp:comment_date_gmt>
        <!-- comment body; use cdata; html allowed (though will be formatted to DISQUS specs) -->
        <wp:comment_content><![CDATA[<?php echo html_entity_decode( $comment->body_html ) ?>]]></wp:comment_content>
        <!-- is this comment approved? 0/1 -->
        <wp:comment_approved><?php echo $comment->status === 'published' ? 1 : 0; ?></wp:comment_approved>
        <!-- parent id (match up with wp:comment_id) -->
        <wp:comment_parent>0</wp:comment_parent>
      </wp:comment>
<?php } ?>
    </item>
<?php } ?>
  </channel>
</rss>
<?php
        $output = ob_get_clean();
        file_put_contents(__DIR__ .'/comments.xml', $output);
    }

    public function syncPage( $handle )
    {
        $from = $this->export( '/admin/pages.json', 'GET', [
            'handle' => $handle
        ]);

        $to = $this->import( '/admin/pages.json', 'GET', [
            'handle' => $handle
        ]);

        if( !($from && count($from->pages) === 1 && $to && count( $to->pages ) === 1) ){
            echo "Can't sync - both pages should exist\n";
            return;
        }

        $from_id = $from->pages[0]->id;
        $to_id = $to->pages[0]->id;

        $metafields = $this->export("/admin/pages/$from_id/metafields.json");
        print_r([$from,$to]);
    }

    public function downloadFiles()
    {
        $response = $this->export( '/admin/settings/files.json', 'GET' );

        print_r( $response );
    }
}

$config = json_decode( file_get_contents( 'config.json' ) );

$tool = new ShopifyImportExport( $config->export, $config->import );
$reflection = new ReflectionClass( $tool );

$valid_commands = array_filter( array_map( function($method){
    $name = $method->getName();
    return substr( $name, 0, 1 ) === '_' ? '' : $method->getName();
}, $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) ), function($name){
    return $name !== '';
} );

if( $argc < 2 || !in_array( $argv[1], $valid_commands )){
    echo "You must provide a valid command:\n";
    foreach( $valid_commands as $command ) echo "  {$command}\n";
    exit;
}
$cmd = $argv[1];
$args = array_slice( $argv, 2 );
call_user_func_array( [$tool, $cmd], $args );
