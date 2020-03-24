# Frontend Crawler Extension for Symphony CMS

-   Version: 1.0.0
-   Date: 24th March 2020
-   [Release notes](https://github.com/pointybeard/crawler/blob/master/CHANGELOG.md)
-   [GitHub repository](https://github.com/pointybeard/crawler)

An extension that crawls a local or remote Symphony CMS powered website profiling pages and reporting any problems.

## Installation

This is an extension for Symphony CMS. Add it to your `/extensions` folder in your Symphony CMS installation, run `composer update` to install required packages and then enable it though the interface.

### Requirements

This extension requires PHP 7.3 or greater.

The following SymphonyCMS Extensions and must be installed first:

-   [Console Extension for Symphony CMS](https://github.com/pointybeard/console)
-   [UUID Field for Symphony CMS](https://github.com/pointybeard/uuidfield)
-   [Number Field](https://github.com/symphonycms/numberfield)
-   [Text Box Field](https://github.com/symphonycms/textboxfield)

The following Composer libraries must be present:

-   [Symfony HttpFoundation Component](https://packagist.org/packages/symfony/http-foundation)
-   [PHP Helpers](https://github.com/pointybeard/helpers)
-   [Symphony CMS: Section Builder](https://packagist.org/packages/pointybeard/symphony-section-builder)
-   [Symphony Class Mapper](https://packagist.org/packages/pointybeard/symphony-classmapper)
-   [PHPUnit: Utility class for timing](https://packagist.org/packages/phpunit/php-timer)

### Setup

1. Run `composer update` on the `extension/crawler` directory to install all composer library dependencies.
2. Use the following commands in your `extensions/` directory to install the required symphony extensions:

```
git clone https://github.com/pointybeard/console.git
git clone https://github.com/symphonycms/numberfield.git
git clone https://github.com/pointybeard/uuidfield.git
git clone https://github.com/symphonists/textboxfield.git
```

3. Then, run `composer update` from inside the `extensions/console` and `extensions/uuidfield` directories to install their composer dependencies.
4. Finally, ensure each of those extensions has been enabled via the admin interface.

## Usage

@TODO

## Support

If you believe you have found a bug, please report it using the [GitHub issue tracker](https://github.com/pointybeard/crawler/issues),
or better yet, fork the library and submit a pull request.

## Contributing

We encourage you to contribute to this project. Please check out the [Contributing documentation](https://github.com/pointybeard/crawler/blob/master/CONTRIBUTING.md) for guidelines about how to get involved.

## License

"Frontend Crawler Extension for Symphony CMS" is released under the [MIT License](http://www.opensource.org/licenses/MIT).
