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
| `statamic.stache.stores.form-submissions.directory` | `/mnt/data/website/shared/form-submissions` |
| `statamic.assets.image_manipulation.cache_path` | `/mnt/data/website/shared/public/glide-cache` |

Statamic 4 reads the form submissions path from `statamic.forms.submissions`, while Statamic 5 and 6 resolve it from the `form-submissions` Stache store. Both keys are set to the same path so submissions land in shared storage on every supported version.

## Symlinked directories

Some directories have to stay inside `public/` to be served by the web server, so the provider symlinks them into shared storage rather than moving them:

| Directory | Linked to | So that |
| --- | --- | --- |
| the Glide route from `statamic.assets.image_manipulation.route` | `/mnt/data/website/shared/public/glide-cache` | generated images survive deploys |
| the `assets` disk, when rooted in `public/` | `/mnt/data/website/shared/public/<directory>` | Control Panel uploads and their `.meta` sidecars survive deploys |

A link is only created when the path is free. Where a real directory is already there the provider leaves it alone, so a site that commits its assets to the repository needs a build step to seed shared storage and create the link before the application boots. [`webslicehq/statamic-starter`](https://github.com/webslicehq/statamic-starter) does this in `.webslice/build.sh`.

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
- Laravel 10.x, 11.x, 12.x or 13.x
- Statamic CMS 4.x, 5.x or 6.x

## License

MIT
