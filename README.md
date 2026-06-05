# This package provides an API client for interfacing with a Perceptive Content system via the Integration Server.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rutgers-oit-eds/laravel-perceptive-content-client.svg?style=flat-square)](https://packagist.org/packages/rutgers-oit-eds/laravel-perceptive-content-client)
[![GitHub Tests Action Status](https://github.com/spatie/package-laravel-perceptive-content-client-laravel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/rutgers-oit-eds/laravel-perceptive-content-client/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/spatie/package-laravel-perceptive-content-client-laravel/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/rutgers-oit-eds/laravel-perceptive-content-client/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/rutgers-oit-eds/laravel-perceptive-content-client.svg?style=flat-square)](https://packagist.org/packages/rutgers-oit-eds/laravel-perceptive-content-client)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-perceptive-content-client.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-perceptive-content-client)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require rutgers-oit-eds/laravel-perceptive-content-client
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-perceptive-content-client-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-perceptive-content-client-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-perceptive-content-client-views"
```

## Usage

```php
$perceptiveClient = new Rutgers\PerceptiveClient();
echo $perceptiveClient->echoPhrase('Hello, Rutgers!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Nicholas Blew](https://github.com/ndblew-rutgers)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
