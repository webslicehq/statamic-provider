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

When `WEBSLICE` is set, the service provider automatically configures your Statamic application for the serverless environment.

### Session & Cache Drivers

| Config Key                  | Value    |
| --------------------------- | -------- |
| `session.driver`            | `cookie` |
| `cache.stores.glide.driver` | `file`   |

### Temporary Storage (`/tmp/storage`)

These paths use ephemeral storage that is unique to each application instance.

| Config Key                | Path                                 |
| ------------------------- | ------------------------------------ |
| `cache.stores.file.path`  | `/tmp/storage/framework/cache/data`  |
| `view.compiled`           | `/tmp/storage/framework/views`       |
| `cache.stores.glide.path` | `/tmp/storage/framework/cache/glide` |

### Persistent Storage (`/mnt/data/website/shared`)

These paths use shared storage that persists between deployments:

| Config Key                                      | Path                                          |
| ----------------------------------------------- | --------------------------------------------- |
| `logging.channels.single.path`                  | `/mnt/data/website/shared/logs/laravel.log`   |
| `logging.channels.daily.path`                   | `/mnt/data/website/shared/logs/laravel.log`   |
| `logging.channels.emergency.path`               | `/mnt/data/website/shared/logs/laravel.log`   |
| `statamic.forms.submissions`                    | `/mnt/data/website/shared/form-submissions`   |
| `statamic.assets.image_manipulation.cache_path` | `/mnt/data/website/shared/public/glide-cache` |

## Glide Image Cache

The provider symlinks the glide-cache route to the shared directory path so your assets can be served from the shared directory.

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
