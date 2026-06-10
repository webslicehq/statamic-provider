<?php

namespace Webslice\StatamicProvider;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Webslice\StatamicProvider\Console\StacheBundleCommand;

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
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([StacheBundleCommand::class]);
        }

        if (! env('WEBSLICE') || env('DISABLE_WEBSLICE_PROVIDER')) {
            return;
        }

        $this->seedStacheFromBundle();
        $this->relocateStacheLocks();
    }

    /**
     * Seed the per-instance stache cache from the release-built bundle.
     *
     * The stache lives in per-instance /tmp, so a fresh instance would
     * otherwise rebuild it lazily by parsing the whole content tree over the
     * network filesystem (10s+ for listing pages, repeated per instance).
     * The release script bundles a warmed stache into the deploy directory
     * (see webslice:stache-bundle); extracting it here turns that rebuild
     * into a single sequential read of one archive.
     *
     * The cache directory may already exist with a partial, lazily-built
     * cache: requests (e.g. platform warmers) can arrive before the release
     * has produced the bundle. Seeding therefore keys off a marker file
     * rather than the directory's existence, and extracts over the top of
     * whatever was built lazily - the bundle is a superset of it. A
     * non-blocking local flock keeps concurrent workers from extracting
     * twice. Any failure falls back to Statamic's lazy rebuild and is
     * retried on the next request.
     */
    private function seedStacheFromBundle(): void
    {
        $bundle = storage_path('statamic/stache-bundle.tar.gz');

        if (! is_file($bundle)) {
            return; // release did not produce a bundle (yet); lazy rebuild applies
        }

        $target = Config::get('cache.stores.file.path') . '/stache';
        $marker = $target . '/.seeded-from-bundle';

        if (is_file($marker)) {
            return; // already seeded on this instance
        }

        $this->ensureDirectoryExists($target);

        $lockHandle = fopen($target . '/.seed-lock', 'c');

        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle !== false) {
                fclose($lockHandle);
            }

            return; // another worker is seeding right now
        }

        $localBundle = self::TEMP_PATH . '/stache-bundle-' . getmypid() . '.tar.gz';

        try {
            if (is_file($marker)) {
                return; // seeded while we acquired the lock
            }

            // Copy the bundle off the network filesystem before extracting:
            // untarring straight from it has tar's gzip child dying with
            // SIGABRT under the fs interceptor, and a single sequential copy
            // is the access pattern the interceptor serves best anyway.
            if (! copy($bundle, $localBundle)) {
                Log::warning("WebsliceProvider: could not copy stache bundle [{$bundle}] to local storage");

                return;
            }

            exec(sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($localBundle), escapeshellarg($target)), $output, $exitCode);

            if ($exitCode !== 0) {
                Log::warning('WebsliceProvider: stache bundle extraction failed: ' . implode("\n", $output));

                return;
            }

            touch($marker);
            Log::info("WebsliceProvider: seeded stache cache from [{$bundle}]");
        } finally {
            @unlink($localBundle);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Move the Statamic Stache lock directory to instance-local storage.
     *
     * The stache cache lives in per-instance /tmp (see configureEnvironment),
     * so the stache-warming lock guarding it must be per-instance too.
     * Statamic hardcodes its lock directory to storage_path('statamic/stache-locks'),
     * which sits on the shared network filesystem - every request then takes a
     * brief exclusive network flock as a gate check (StacheLock middleware),
     * and instances stall each other even though each instance only ever warms
     * its own private cache.
     *
     * This must run in boot() rather than register() because it resolves the
     * Stache singleton, which Statamic's own service provider registers.
     */
    private function relocateStacheLocks(): void
    {
        if (! class_exists(\Statamic\Stache\Stache::class)) {
            Log::debug('WebsliceProvider: Statamic is not installed, skipping stache lock relocation');
            return;
        }

        $dir = self::TEMP_PATH . '/statamic/stache-locks';
        $this->ensureDirectoryExists($dir);

        $this->app->make(\Statamic\Stache\Stache::class)
            ->setLockFactory(new LockFactory(new FlockStore($dir)));
    }

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

        $this->setupGlideCache();
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
