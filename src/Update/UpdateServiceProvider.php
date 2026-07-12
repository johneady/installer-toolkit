<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InstallerToolkit\Update\Console\Commands\PruneUpdateArtifacts;
use InstallerToolkit\Update\Console\Commands\UpdateHistoryCommand;
use InstallerToolkit\Update\Console\Commands\UpdateKeygenCommand;
use InstallerToolkit\Update\Console\Commands\UpdateRollbackCommand;
use InstallerToolkit\Update\Http\ChunkedUploadController;

class UpdateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/updates.php', 'updates');

        $this->app->singleton(UpdateBackupManager::class);
        $this->app->singleton(UpdateService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->offerPublishing();
            $this->commands([
                PruneUpdateArtifacts::class,
                UpdateRollbackCommand::class,
                UpdateHistoryCommand::class,
                UpdateKeygenCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if (config('updates.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    protected function registerRoutes(): void
    {
        $config = config('updates.routes', []);

        Route::group([
            'prefix' => $config['prefix'] ?? 'system-update/upload',
            'middleware' => $config['middleware'] ?? ['web', 'auth'],
        ], function (): void {
            $name = (string) (config('updates.routes.name') ?? 'chunked-upload');

            Route::post('initialize', [ChunkedUploadController::class, 'initialize'])->name($name.'.initialize');
            Route::post('chunk', [ChunkedUploadController::class, 'chunk'])->name($name.'.chunk');
            Route::post('assemble', [ChunkedUploadController::class, 'assemble'])->name($name.'.assemble');
        });
    }

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../../config/updates.php' => $this->app->configPath('updates.php'),
        ], 'update-config');

        $this->publishes([
            __DIR__.'/../../database/migrations/2024_01_01_000000_create_update_history_table.php' => $this->app->databasePath('migrations/2024_01_01_000000_create_update_history_table.php'),
        ], 'update-migrations');

        $this->publishes([
            __DIR__.'/../../stubs/Filament/Pages/SystemUpdate.php' => $this->app->path('Filament/Pages/SystemUpdate.php'),
            __DIR__.'/../../stubs/Filament/Pages/UpdateHistory.php' => $this->app->path('Filament/Pages/UpdateHistory.php'),
            __DIR__.'/../../stubs/views/filament/pages/system-update.blade.php' => $this->app->resourcePath('views/filament/pages/system-update.blade.php'),
            __DIR__.'/../../stubs/views/filament/pages/update-history.blade.php' => $this->app->resourcePath('views/filament/pages/update-history.blade.php'),
        ], 'update-filament');

        $this->publishes([
            __DIR__.'/../../stubs/routes/updates.php' => $this->app->basePath('routes/updates.php'),
        ], 'update-routes');

        $this->publishes([
            __DIR__.'/../../stubs/public/update.php' => $this->app->publicPath('update.php'),
            __DIR__.'/../../stubs/public/shared_update.php' => $this->app->publicPath('shared_update.php'),
        ], 'update-shared-hosting');

        $this->publishes([
            __DIR__.'/../../config/updates.php' => $this->app->configPath('updates.php'),
            __DIR__.'/../../database/migrations/2024_01_01_000000_create_update_history_table.php' => $this->app->databasePath('migrations/2024_01_01_000000_create_update_history_table.php'),
            __DIR__.'/../../stubs/Filament/Pages/SystemUpdate.php' => $this->app->path('Filament/Pages/SystemUpdate.php'),
            __DIR__.'/../../stubs/Filament/Pages/UpdateHistory.php' => $this->app->path('Filament/Pages/UpdateHistory.php'),
            __DIR__.'/../../stubs/views/filament/pages/system-update.blade.php' => $this->app->resourcePath('views/filament/pages/system-update.blade.php'),
            __DIR__.'/../../stubs/views/filament/pages/update-history.blade.php' => $this->app->resourcePath('views/filament/pages/update-history.blade.php'),
            __DIR__.'/../../stubs/routes/updates.php' => $this->app->basePath('routes/updates.php'),
            __DIR__.'/../../stubs/public/update.php' => $this->app->publicPath('update.php'),
            __DIR__.'/../../stubs/public/shared_update.php' => $this->app->publicPath('shared_update.php'),
        ], 'update-toolkit');
    }
}
