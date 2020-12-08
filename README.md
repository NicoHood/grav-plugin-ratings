# Ratings Plugin

**This README.md file should be modified to describe the features, installation, configuration, and general usage of the plugin.**

The **Ratings** Plugin is an extension for [Grav CMS](http://github.com/getgrav/grav). A plugin for add 5 star ratings and reviews to grav pages.

## Installation

Installing the Ratings plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](http://learn.getgrav.org/advanced/grav-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install ratings

This will install the Ratings plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/ratings`.

### Manual Installation

To install the plugin manually, download the zip-version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `ratings`. You can find these files on [GitHub](https://github.com/nicohood/grav-plugin-ratings) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under

    /your/site/grav/user/plugins/ratings

> NOTE: This plugin is a modular component for Grav which may require other plugins to operate, please see its [blueprints.yaml-file on GitHub](https://github.com/nicohood/grav-plugin-ratings/blob/master/blueprints.yaml).

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/ratings/ratings.yaml` to `user/config/plugins/ratings.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
```

Note that if you use the Admin Plugin, a file with your configuration named ratings.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Features

- [x] Grav 1.6 and 1.7 compatible

## Usage

##### Requirements:

* SQLite >=3.16 (Debian Stretch or newer)
* [Database Plugin](https://github.com/getgrav/grav-plugin-database)
* [Form Plugin](https://github.com/getgrav/grav-plugin-form)
* [Email Plugin](https://github.com/getgrav/grav-plugin-email)
* Grav >=1.6
* A theme with FontAwesome4, FontAwesome5 oder LineAwesome icon support
* The [session](https://learn.getgrav.org/16/basics/grav-configuration#session) feature in `system.yaml` must be enabled.

##### Optional:

* [NicoHood's Seo Plugin (json-ld support)](https://github.com/NicoHood/grav-plugin-seo)

#### Language support

It is recommented to set supported languages in `system.yaml` in order to save the language code of the user inside the rating (even if you do not yet use more than one language):

```yaml
languages:
  translations: true
  http_accept_language: true
  include_default_lang: false
  default_lang: en
  supported:
   - en
```

## Credits

**Did you incorporate third-party code? Want to thank somebody?**

## To Do

- [ ] Future plans, if any

