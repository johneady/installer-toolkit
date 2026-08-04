<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InstallerToolkit\Update\Console\Commands\PruneUpdateArtifacts;
use InstallerToolkit\Update\Console\Commands\UpdateKeygenCommand;
use InstallerToolkit\Update\Http\LaunchUpdaterController;

class UpdateServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../config/updates.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'updates');

        $this->mergeSigningDefaults();
    }

    /**
     * Backfill the build-time signing key entries when a published
     * config/updates.php declares `signing` without them.
     *
     * mergeConfigFrom() is a shallow array_merge, so an app that publishes
     * `signing` to pin its own trusted_keys replaces the whole array and
     * loses sibling keys the package adds later — package:build then resolves
     * an empty key and fails a signed build. Only the two private-key entries
     * are backfilled, and only when absent.
     *
     * Deliberately NOT a blanket replaceConfigRecursivelyFrom(): that would
     * also union `trusted_keys`, re-adding package keys an app had removed
     * and making it impossible to revoke a trust anchor from the app side.
     * trusted_keys must stay a wholesale override.
     */
    protected function mergeSigningDefaults(): void
    {
        // Mirrors mergeConfigFrom()'s own guard. A cached config is already
        // fully resolved, so there is nothing to backfill — and re-reading
        // the package config on every request would both waste a disk read
        // and defeat the point of config:cache.
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        $signing = $config->get('updates.signing');
        $signing = is_array($signing) ? $signing : [];

        $missing = array_diff(['private_key', 'private_key_file'], array_keys($signing));

        if ($missing === []) {
            return;
        }

        // Taken from the package's own config file rather than env() so the
        // defaults have a single source of truth. Only read once a key is
        // known to be missing, which is the uncommon case.
        $packageSigning = (require self::CONFIG_PATH)['signing'] ?? [];

        foreach ($missing as $key) {
            $config->set("updates.signing.{$key}", $packageSigning[$key] ?? '');
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->offerPublishing();
            $this->commands([
                PruneUpdateArtifacts::class,
                UpdateKeygenCommand::class,
            ]);
        }

        if (config('updates.updater.enabled', true)) {
            $this->registerRoutes();
        }
    }

    protected function registerRoutes(): void
    {
        $config = config('updates.updater', []);

        Route::post(
            (string) ($config['launch_path'] ?? 'system-update/launch'),
            [LaunchUpdaterController::class, '__invoke'],
        )
            ->middleware((array) ($config['middleware'] ?? ['web', 'auth']))
            ->name('updater.launch');
    }

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../../config/updates.php' => $this->app->configPath('updates.php'),
        ], 'update-config');

        $this->publishes([
            __DIR__.'/../../stubs/Filament/Pages/SystemUpdate.php' => $this->app->path('Filament/Pages/SystemUpdate.php'),
            __DIR__.'/../../stubs/views/filament/pages/system-update.blade.php' => $this->app->resourcePath('views/filament/pages/system-update.blade.php'),
        ], 'update-filament');

        $this->publishes([
            __DIR__.'/../../config/updates.php' => $this->app->configPath('updates.php'),
            __DIR__.'/../../stubs/Filament/Pages/SystemUpdate.php' => $this->app->path('Filament/Pages/SystemUpdate.php'),
            __DIR__.'/../../stubs/views/filament/pages/system-update.blade.php' => $this->app->resourcePath('views/filament/pages/system-update.blade.php'),
        ], 'update-toolkit');
    }
}
