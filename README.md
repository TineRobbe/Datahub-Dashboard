# Datahub Dashboard

[![Software License][ico-license]](LICENSE)

The Datahub Dashboard is an application which allows users and data providers to measure the completeness, unambiguity, richness and openness of their metadata. The metadata is retrieved from an OAI-PMH endpoint, for example a [Datahub](https://github.com/thedatahub/Datahub) application.

The application can currently only handle LIDO data schemes, but can be easily extended to handle other, similar schemes as well.

## Requirements

This project requires following dependencies:
* PHP >= 7.1.3
  * With the php-cli and php-xml extensions.
  * The [PECL Mongodb](https://pecl.php.net/package/mongodb) (PHP7) extension. Note that the _mongodb_ extension must be version 1.2.0 or higher. Notably, the package included in Ubuntu 16.04 (_php-mongodb_) is only at 1.1.5.

    To install PECL and mongodb:
      ```bash
      $ apt-get install php-pear
      $ pecl install mongodb
      ```
* MongoDB >= 3.2.10

## Install

Via Git:

```bash
$ git clone https://github.com/thedatahub/Datahub-Dashboard
$ cd Datahub-Dashboard
$ composer install # Composer will ask you to fill in any missing parameters before it continues
```

You will be asked to configure the connection to your MongoDB database. You will need to provide these details (but can currently be skipped due to still being in development):

* The connection to your MongoDB instance (i.e. mongodb://127.0.0.1:27017)
* The username of the user (i.e. datahub_dashboard)
* The password of the user
* The database where your data will persist (i.e. datahub_dashboard)

Before you install, ensure that you have a running MongoDB instance, and you 
have created a user with the right permissions. From the 
[Mongo shell](https://docs.mongodb.com/getting-started/shell/client/) run these
commands to create the required artefacts in MongoDB:

```
> use datahub_dashboard
> db.createUser(
   {
     user: "datahubdashboarduser",
     pwd: "datahubdashboardpass",
     roles: [ "readWrite", "dbAdmin" ]
   }
)
```

If you want to run the dashboard for testing or development purposes, execute this command:

``` bash
$ php bin/console server:run
```

Use a browser and Navigate to [http://127.0.0.1:8000](http://127.0.0.1:8000). 
You should now see the dashboard homepage.

Refer to the [Symfony setup documentation](https://symfony.com/doc/current/setup/web_server_configuration.html) 
to complete your installation using a fully featured web server to make your 
installation operational in a production environment.

## Usage

### Initial setup

In order to fill up the dashboard database with the necessary metadata, run this command:
```bash
$ php bin/console app:fetch-data
```

The command takes an optional parameter, namely the URL of the OAI-PMH endpoint. This URL can also be configured in config/parameters.yml.
In order to update the data on a regular basis, consider putting the command in a cron job.

## Front end development

Front end workflows are managed via [yarn](https://yarnpkg.com/en/) and 
[webpack-encore](https://symfony.com/blog/introducing-webpack-encore-for-asset-management).

The layout is based on [Bootstrap 3.3](https://getbootstrap.com/docs/3.3/) 
and managed via sass. The code can be found under `app/resources/public/sass`.

Javascript files can be found under `assets/js`. Dependencies are 
managed via `yarn`. Add vendor modules using `require`.

Files are build and stored in `web/build` and included in `app/Resources/views/base.html.twig`
via the `asset()` function.

The workflow configuration can be found in `webpack.config.js`.

Get started:

```
# Install all dependencies
$ yarn install

# Build everything in development
$ yarn run encore dev

# Watch files and build automatically
$ yarn run encore dev --watch

# Build for production
$ yarn run encore production
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Authors

[All Contributors][link-contributors]

## Copyright and license

The Datahub Dashboard is copyright (c) 2018 by Vlaamse Kunstcollectie vzw.

This is free software; you can redistribute it and/or modify it under the 
terms of the The GPLv3 License (GPL). Please see [License File](LICENSE) for 
more information.

[ico-version]: https://img.shields.io/packagist/v/:vendor/:package_name.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-GPLv3-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/:vendor/:package_name/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/:vendor/:package_name.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/:vendor/:package_name.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/:vendor/:package_name.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/:vendor/:package_name
[link-travis]: https://travis-ci.org/:vendor/:package_name
[link-scrutinizer]: https://scrutinizer-ci.com/g/:vendor/:package_name/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/:vendor/:package_name
[link-downloads]: https://packagist.org/packages/:vendor/:package_name
[link-author]: https://github.com/:author_username
[link-contributors]: ../../contributors
