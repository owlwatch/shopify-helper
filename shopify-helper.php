#!/usr/bin/php
<?php
class ShopifyImportExport
{
    public function __construct( $export_url, $import_url )
    {
        $this->export_url = $export_url;
        $this->import_url = $import_url;
    }

    public function call($url, $method="GET", $params=[])
    {
        preg_match('#https://(.*?)@(.*)$#', $url, $matches);
        $userpass = $matches[1];
        $url = 'https://'.$matches[2];

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            // CURLOPT_VERBOSE        => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $userpass,
        ];
        switch ($method) {
            case 'POST':
            case 'post':
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
            case 'DELETE':
            case 'delete':
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

        if ($method === 'POST') {
            //print_r( ['response'=>$json, 'info'=>curl_getinfo($ch)] );
        }

        return json_decode($json);
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
}

$config = json_decode( file_get_contents( 'config.json' ) );

$tool = new ShopifyImportExport( $config->export, $config->import );


$valid_commands = ["importPages","importBlogs","exportComments","test"];

if( $argc < 2 || !in_array( $argv[1], $valid_commands )){
    echo "You must provide a valid command:\n";
    foreach( $valid_commands as $command ) echo "  {$command}\n";
    exit;
}
$cmd = $argv[1];
$tool->$cmd();
