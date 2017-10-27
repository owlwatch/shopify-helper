# Shopify Help Utility

This tool was created to help copy pages and blog posts from one Shopify store to another. It is written in php,
so you will need to have php installed to run this tool.

## Configuration

You will need to [create private apps](https://help.shopify.com/manual/apps/private-apps) for both stores 
where you want to run this utility. 

You need to create a `config.json` file with the credentials for the private apps created for each store. 
See config.sample.json for the format. The "export" store is the store you are exporting from, "import" is
the store you are importing to.

## Usage

The utility is just run as a php script on the command line:

```
php ./shopify-helper.php <command>
```

The available commands are `test`, `importPages`, `importBlogs`, and `exportComments`.

The import commands should **only be run once**, otherwise it will create duplicate content on the target store.

