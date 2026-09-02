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

    private const LINK_OK = 'ok';
    private const LINK_OCCUPIED = 'occupied';
    private const LINK_FAILED = 'failed';

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
        // Statamic 4 reads the submissions path from statamic.forms.submissions. Statamic 5 and 6
        // resolve it from the form-submissions Stache store instead, so set both to the same path.
        $sharedDirConfigMap = [
            'statamic.forms.submissions'                        => self::SHARED_PATH . '/form-submissions',
            'statamic.stache.stores.form-submissions.directory' => self::SHARED_PATH . '/form-submissions',
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

        $this->setupGlideCache();
        $this->setupAssetDisk();
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

        if ($this->linkToShared($link, $target) === self::LINK_OCCUPIED) {
            Log::error("WebsliceProvider: Link [$link] already exists, not creating symlink");
        }
    }

    /**
     * Link the asset container to shared storage so Control Panel uploads, and
     * the .meta sidecars holding their alt text, survive a versioned deploy.
     */
    private function setupAssetDisk(): void
    {
        $root = Config::get('filesystems.disks.assets.root');

        if (empty($root)) {
            Log::debug('WebsliceProvider: No assets disk configured, skipping setup');
            return;
        }

        // Any other root is already outside the deploy directory, or served
        // through PHP, so a symlink would not help it.
        if (! str_starts_with($root, public_path() . DIRECTORY_SEPARATOR)) {
            Log::debug("WebsliceProvider: Assets disk root [$root] is not in the public directory, skipping setup");
            return;
        }

        $target = self::SHARED_PATH . '/public/' . basename($root);
        $this->ensureDirectoryExists($target);

        if ($this->linkToShared($root, $target) === self::LINK_OCCUPIED) {
            Log::debug("WebsliceProvider: Assets disk root [$root] already exists, leaving it alone");
        }
    }

    /**
     * Symlink a path to shared storage, never replacing what is already there.
     * Callers decide how loudly to report LINK_OCCUPIED.
     */
    private function linkToShared(string $link, string $target): string
    {
        if (is_link($link)) {
            return readlink($link) === $target ? self::LINK_OK : self::LINK_OCCUPIED;
        }

        // Whatever is here was deployed, so replacing it could remove files.
        if (file_exists($link)) {
            return self::LINK_OCCUPIED;
        }

        $this->ensureDirectoryExists(dirname($link));

        if (! @symlink($target, $link)) {
            Log::error("WebsliceProvider: Could not create symlink from [$link] to [$target]: " . (error_get_last()['message'] ?? 'Unknown error'));

            return self::LINK_FAILED;
        }

        Log::info("WebsliceProvider: Created symlink from [$link] to [$target]");

        return self::LINK_OK;
    }
}
