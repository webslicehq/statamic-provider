<?php

namespace Webslice\StatamicProvider\Console;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Warm the Statamic stache and bundle it into the deploy directory.
 *
 * Intended to run from the release script. The stache cache lives in
 * per-instance /tmp on Webslice, so warming alone only benefits the machine
 * that ran it - this command tars the warmed cache into the deploy directory
 * where WebsliceServiceProvider::seedStacheFromBundle() extracts it on each
 * instance's cold start, replacing a 10s+ content-tree parse with a bulk copy.
 */
class StacheBundleCommand extends Command
{
    protected $signature = 'webslice:stache-bundle';

    protected $description = 'Warm the Statamic stache and bundle it into the deploy directory for cold-start seeding';

    public function handle(): int
    {
        if (! class_exists(\Statamic\Facades\Stache::class)) {
            $this->error('Statamic is not installed.');

            return self::FAILURE;
        }

        $this->info('Warming the stache...');
        \Statamic\Facades\Stache::refresh();

        $store = \Statamic\Facades\Stache::cacheStore()->getStore();

        if (! method_exists($store, 'getDirectory')) {
            $this->error('The stache cache store is not a file store; nothing to bundle.');

            return self::FAILURE;
        }

        // Statamic's FileStore extension keeps stache keys under a stache/
        // subdirectory of the store path.
        $source = rtrim($store->getDirectory(), '/') . '/stache';

        if (! is_dir($source)) {
            $this->error("Warmed stache directory [{$source}] does not exist.");

            return self::FAILURE;
        }

        $bundle = storage_path('statamic/stache-bundle.tar.gz');

        if (! is_dir(dirname($bundle)) && ! mkdir(dirname($bundle), 0755, true) && ! is_dir(dirname($bundle))) {
            throw new RuntimeException("Directory [" . dirname($bundle) . "] could not be created.");
        }

        // Build to a temp file and rename so a half-written bundle can never
        // be picked up by an instance booting mid-release.
        $tmp = $bundle . '.tmp';

        exec(sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($tmp), escapeshellarg($source)), $output, $exitCode);

        if ($exitCode !== 0) {
            @unlink($tmp);
            $this->error('tar failed: ' . implode("\n", $output));

            return self::FAILURE;
        }

        if (! rename($tmp, $bundle)) {
            @unlink($tmp);
            $this->error("Could not move bundle into place at [{$bundle}].");

            return self::FAILURE;
        }

        $this->info(sprintf('Stache bundled to %s (%.1f KB)', $bundle, filesize($bundle) / 1024));

        return self::SUCCESS;
    }
}
