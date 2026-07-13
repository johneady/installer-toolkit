<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InstallerToolkit\Update\Console\Commands\PruneUpdateArtifacts;
use InstallerToolkit\Update\Console\Commands\UpdateKeygenCommand;
use InstallerToolkit\Update\Http\LaunchUpdaterController;

class UpdateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/updates.php', 'updates');
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
