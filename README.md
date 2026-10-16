# Printful Plugin

The **Printful** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It allows KahunaCart to order items from Printful templates

## Installation

Installing the Printful plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install printful

This will install the Printful plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/printful`.

### Manual Installation

To install the plugin manually, download the zip-version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `printful`. You can find these files on [GitHub](https://github.com/nigelwhitfield/grav-plugin-printful) or via [GetGrav.org](https://getgrav.org/downloads/plugins).

You should now have all the plugin files under

    /your/site/grav/user/plugins/printful
	
> NOTE: This plugin is a modular component for Grav which may require other plugins to operate, please see its [blueprints.yaml-file on GitHub](https://github.com/nigelwhitfield/grav-plugin-printful/blob/main/blueprints.yaml).

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Configure the plugin via its in-built settings screen. These are the options available:

#### Printful API key
This is the API key that allows the plugin to access Printful, to place orders, or generate a product catalog

#### Printful wait time
A number, in hours. This is checked by the background task, and an order will not be despatched to Printful until it has elapsed. Set to zero to ensure orders are despatched within half an hour of being placed. Or set a higher value if you want, for example, to give customers a cooling off time, or to allow for payment to be received by you, since Printful will charge you on order creation, while payment providers may take some time to deposit the customer payment in your account.

#### Create Printful order as draft
If enabled, then orders will be submitted to Printful with draft status, which means you need to confirm them in the Printful Dashboard before they will be queued for production. Again, this option can be used to defer orders until you have received the customer payment, but if you don't confirm orders on Printful, they will never be processed.

#### Email on order creation
If enabled, the store email address you enter further down the page will receive a confirmation when an order has been submitted to Printful.

#### Size units
Centimetres or inches. Used when a catalog is imported from Printful with the --sizes option

#### Printful order prefix
Enter a string that will be prefixed to your order number in the Printful dashboard, eg 'KC' for Kahunacart. If your Printful account receives orders from multiple connections, this can make it easier to scan the dashboard for those submitted through Kahunacart

#### Email address for your store
This is the email address that will be displayed on your Printful packing slips. If specified, this and the following options will override any options configured in the Store settings on Printful. This means you can keep all that config in one place, instead of switching between Printful and Kahunacart

#### Packing label logo URL
Enter a full URL to a logo image is publicly accessible. It will be printed on the packing slips. Email address must be specified for this option to take effect

#### Store name
The name of your store, for packing slips. Email address must be specified

#### Phone number
Contact number for your store. Email address must be specified

#### Packing slip message
A short message like `Thanks for supporting us` that will appear on packing slips. Email address must be specified.

Note that if you use the Admin Plugin, a file with your configuration named printful.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

This plugin relies on a [Printful](https://prinful.com) account with at least one store, and an API key created for that store.

When an order is paid, and contains one or more items that has a Printful id, the printful_status field for the order is set to
"queued"

The printful-process job runs every half an hour, and checks for orders where printful_status = queued

All line items in the order are checked, to see which have a printful_variant id greater than zero.

If any are found, and the specified wait period has elapsed, a Printful order is built, combining all the items, and despatched to the Printful API. Errors appear in the Grav logs. If the order is accepted by Printful, the printful_status for the order is updated to "submitted"

Further updates to the status will be generated by Printful webhooks.

### Printful setup
On the Printful side, you need to register for a Printful account, and then create a store using the 'Connect via API option.' Give your store a name that you can recognise. Then from settings, choose Store Settings, then API. Select the store you just created and click the 'Go to Printful Developers' button. Sign in with your Printful account. 

Click to create a Private Token.  Give it a name like 'Kahunacart' and enter an email address. Set an expiry date some time in the future - it can be up to two years. Then select Single store for the access level, and pick the store you created on the main Printful site.

Choose the permissions to view and manage orders, view and manage store products, view and manage store files, view and manage store webhooks.

The key will be displayed; save it somewhere safe, as it will not be displayed again. You also need to enter this key as the Printful API key in the plugin settings.

#### Printful products
Create your products on Printful, using the design tools. You can create a template and add it it to your store later, or create a product and select the store at the start of the design process. For each product, you can select the various sizes and colour variants. The title you give the product will be the title use in your Kahunacart shop. You will see the products added to your store on the Stores tab in Printful. You can now use the plugin to create items on Kahunacart 

### Catalog creation
The plugin includes a command line tool that will generate a YAML catalog file from Printful, and download product images. 

Quick catalog creation:

`bin/plugin printful catalog --save --all`

The plugin will report the name of the file, eg user/data/printful/my-shop-1234567890.yaml, where the number is a timestamp. You can import this directly into Kahnunacart with the command

`bin/plugin kahunacart import user/data/prinful/my-shop-1234567890.yaml`

**Note that you should really check the YAML file first, and the `--dry-run` flag to see what will happen.**

There are also many options that can be configured for the catalog import; `--all` is a quick option that selects most of the things you will want, but may not in itself be sufficient. These are all the options available:

+ `--key=PRINTFUL_API_KEY` Use this if you want to import the products from a different Printful store that has a separate API key.
+ `--brand='My Brand Name'` Sets the brand name of your imported products. Defaults to Printful if not specified
+ `--manufacturer='My manufacturer'` Sets the manufacturer of your product. Create a manufacturer in Kahnunacart with default safety and contact infomation. If not specified, will not be included in the catalog file
+ `--publish` If specified, products will be published in Kahunacart on import. If omitted, they will appear as drafts
+ `--descriptions` If specified, Printful product descriptions will be imported. Lines beginning with a bullet point will be converted into a proper markdown list
+ `--types` If specified, Printful product type names will be used as Kahunacart summaries, eg 'Bella + Canvas 3001 | Unisex Jersey Short Sleeve Tee'
+ `--categories` If specified, the main product category for each item will be imported, and categories created automatically in Kahunacart. Only the Printful main product category is used.
+ `--images` References to the images will be added to the catalog data. Images are not automatically downloaded unless the --save option is added. Images are named for the printful variant id, or (or product id if no variants), for example `printful_p_1234567890.png` or `printful_v_1234567890.png`
+ `--sizes` If specified, sizing information is requested from Printful, using the units set on the plugin configuration screen. Data is formatted into a MarkDown table. If no value is specified, and attribute called 'Sizing' with the slug 'sizing' is created. You can also specify --sizes=Measurements or some other label, to create an attribute with the given name and the matching slug.
+ `--save` If specified, the data is written to a YAML file, and the name is displayed. Otherwise, it is output to the console. If --images has been specified, then the images are saved in user/images/kahunacart/products
+ `--all` A quick option that selects --descriptions, --types, --categories, --images and --sizes.

### Order updates
To track the progress of Printful orders, you need to configure a webhook on Printful, which will update the status as orders progress through their system, and pass on notifications when a package is shipped. A Grav API key needs to be created for this. I recommend creating a new user, with minimal permissions. It needs to be granted api.access and printful.webhook. You can create this in Grav admin, then at the bottom of the page, generate an API key for the user.

To enable the webhooks use the cli command `bin/plugin printful webhook:enable --api_key=grav12345678990`

You can optionally pass the `--key` parameter to specify a Printful API key, if you have not yet configured your store in the admin panel, or wish to enable hooks for a different store (which will likely cause chaos, as order numbers won't match)

To disable the webhooks, use the cli command `bin/plug printful webhook:disable`

This too can optionally take a `--key` parameter for a Printful API key.

## Credits

Nigel Whitfield, with many thanks to the Kahanucart developers

## To Do

- [ ] Handle edited orders where a Printful items is removed (only fully cancelled orders are handled)
- [ ] Handle orders that are cancelled on Printful (remove from Kahunacart order)
- [ ] Move config into KahunaCart panel?
- [ ] Integrate with admin-next
