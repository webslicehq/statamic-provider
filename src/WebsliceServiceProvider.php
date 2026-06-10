<?php

namespace Webslice\StatamicProvider;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Webslice service provider for Statamic CMS.
 *
 * Configures Statamic applications for Webslice serverless environments,
 * including cache paths, session drivers, and Glide image manipulation cache.
 */
class WebsliceServiceProvider extends ServiceProvider
{
    // Define base paths
    private const TEMP_PATH = '/tmp/storage';
    private const SHARED_PATH = '/mnt/data/website/shared';

    /**
     * Register services.
     */
    public function register(): void
    {
        if (! env('WEBSLICE')) {
            Log::debug('WEBSLICE environment variable is not set, skipping webslice service provider');
            return;
        }

        if (env('DISABLE_WEBSLICE_PROVIDER')) {
            Log::debug('DISABLE_WEBSLICE_PROVIDER environment variable is set, skipping webslice service provider');
            return;
        }

        $this->configureEnvironment();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}

    /**
     * Configure Statamic environment for serverless deployment.
     *
     * Sets up cache paths, session drivers, and Statamic Glide configuration.
     */
    private function configureEnvironment(): void
    {
        Config::set('cache.stores.glide.driver', 'file');
        Config::set('session.driver', 'cookie');

        // Temp paths - ephemeral storage that doesn't need to persist
        $tempConfigMap = [
            'cache.stores.file.path'  => self::TEMP_PATH . '/framework/cache/data',
            'view.compiled'           => self::TEMP_PATH . '/framework/views',
            'cache.stores.glide.path' => self::TEMP_PATH . '/framework/cache/glide',
        ];

        // Shared file paths - persistent storage that survives deployments (create parent dir)
        $sharedFileConfigMap = [
            'logging.channels.single.path'    => self::SHARED_PATH . '/logs/laravel.log',
            'logging.channels.daily.path'     => self::SHARED_PATH . '/logs/laravel.log',
            'logging.channels.emergency.path' => self::SHARED_PATH . '/logs/laravel.log',
        ];

        // Shared directory paths - persistent storage that survives deployments (create dir itself)
        $sharedDirConfigMap = [
            'statamic.forms.submissions' => self::SHARED_PATH . '/form-submissions',
        ];

        foreach ($tempConfigMap as $configKey => $path) {
            $this->ensureDirectoryExists($path);
            Config::set($configKey, $path);
        }

        foreach ($sharedFileConfigMap as $configKey => $path) {
            $this->ensureDirectoryExists(dirname($path));
            Config::set($configKey, $path);
        }

        foreach ($sharedDirConfigMap as $configKey => $path) {
            $this->ensureDirectoryExists($path);
            Config::set($configKey, $path);
        }

        $this->configureStacheStore();
        $this->setupGlideCache();
    }

    /**
     * Keep the Statamic stache in deploy-local shared storage.
     *
     * The default file cache store lives in per-instance /tmp (see above), which
     * forces every fresh instance to rebuild the stache from the content tree on
     * its first requests. The stache is warmed once per release by
     * `php please stache:refresh` and is read-only afterwards (the file watcher
     * is disabled in production), so a dedicated store on the deploy directory
     * lets every instance read the cache built at release time instead.
     *
     * The store sits under the deploy directory rather than SHARED_PATH so the
     * stache is versioned with the content it indexes - a new deploy serves the
     * stache its own release step built, never a previous release's.
     */
    private function configureStacheStore(): void
    {
        $path = storage_path('framework/cache/stache');
        $this->ensureDirectoryExists($path);

        Config::set('cache.stores.stache', [
            'driver' => 'file',
            'path'   => $path,
        ]);
        Config::set('statamic.stache.cache_store', 'stache');

        // The StacheLock middleware takes a brief exclusive flock on shared
        // storage for every request as a "warming in progress" gate. With the
        // stache warmed at release time before traffic reaches the deploy, the
        // gate guards nothing and only adds network lock contention - disable it.
        // The warming lock itself stays at Statamic's default (also on the deploy
        // directory), which correctly serializes the release-time warm.
        Config::set('statamic.stache.lock.enabled', false);
    }

    /**
     * Safely create a directory if it does not exist.
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new RuntimeException("WebsliceProvider: Directory [{$path}] could not be created.");
            }
        }
    }

    /**
     * Set up Statamic Glide cache symlink for persistent image manipulation cache.
     *
     * Creates a symlink from the public route to the shared directory
     * so the Glide cache persists between serverless deployments.
     */
    private function setupGlideCache(): void
    {
        $route = Config::get('statamic.assets.image_manipulation.route');
        if (empty($route)) {
            Log::debug("WebsliceProvider: Statamic Glide cache route is not set, skipping setup");
            return;
        }

        // Set the cache_path to the shared directory so it persists between deploys
        // Link the public route to the shared directory so it can be served
        $target   = self::SHARED_PATH . '/public/glide-cache';
        $link = public_path($route);

        Config::set('statamic.assets.image_manipulation.cache_path', $target);
        $this->ensureDirectoryExists(dirname($target));
        $this->ensureDirectoryExists(dirname($link));

        if (is_link($link) && readlink($link) === $target) {
            return;
        }

        // If something already exists at $link and it isn't the symlink we want, we don't want to overwrite it.
        if (file_exists($link)) {
            Log::error("WebsliceProvider: Link [$link] already exists, not creating symlink");
            return;
        }

        if (! @symlink($target, $link)) {
            Log::error("WebsliceProvider: Could not create symlink from [$link] to [$target]: " . (error_get_last()['message'] ?? 'Unknown error'));
        } else {
            Log::info("WebsliceProvider: Created symlink from [$link] to [$target]");
        }
    }
}
