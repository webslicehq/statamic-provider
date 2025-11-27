# Webslice Statamic Provider

A Statamic CMS service provider for configuring Webslice serverless environments.

## Installation

You can install the package via Composer:

```bash
composer require webslicehq/statamic-provider
```

The service provider will be automatically discovered by Laravel.

## Configuration

The provider will only run when the environment variable `WEBSLICE` is set to `true`, this is automatically added to the environment when deploying to the Webslice Serverless platform (to disable the provider in Webslice you can set the environment variable `DISABLE_WEBSLICE_PROVIDER` to `true`).

When `WEBSLICE` is set, the service provider will:

- Configure cache and session drivers for serverless environments
- Set up temporary storage paths (`/tmp/storage`)
- Configure Statamic Glide image manipulation cache paths
- Create necessary directories and symlinks for persistent Glide cache

## Manual Registration

If you need to manually register the service provider, add it to the `providers` array in `config/app.php`:

```php
'providers' => [
    // ...
    Webslice\StatamicProvider\WebsliceServiceProvider::class,
],
```

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x
- Statamic CMS 4.x or 5.x

## License

MIT
