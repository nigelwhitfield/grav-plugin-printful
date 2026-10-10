# Printful Plugin

**This README.md file should be modified to describe the features, installation, configuration, and general usage of the plugin.**

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

Before configuring this plugin, you should copy the `user/plugins/printful/printful.yaml` to `user/config/plugins/printful.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
```

Note that if you use the Admin Plugin, a file with your configuration named printful.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

This plugin relies on a [Printful](https://prinful.com) account with at least one store, and an API key created for that store.

When an order is paid, and contains one or more items that has a Printful template id, the printful_status field for the order is set to
"pending"

The printful-process job runs every half an hour, and first checks for orders where printful_status = pending

All line items in the order are checked, to see which have a printful_variant id greater than zero.

A printful order is built, combining all the items, and despatched to the Printful API. Errors appear in the Grav logs. If the order is accepted by Printful, the printful_status for the order is updated to "created"



## Credits

**Did you incorporate third-party code? Want to thank somebody?**

## To Do

- [ ] Automatically import products and variants from Printful templates
- [ ] Handle cancelled orders
- [ ] Update shipment status
- [ ] Flag errors via store notices
- [ ] Move config into KahunaCart panel

