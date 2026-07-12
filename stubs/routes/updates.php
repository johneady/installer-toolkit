<?php

declare(strict_types=1);

/**
 * Optional routes stub for johneady/installer-toolkit.
 *
 * The toolkit registers its chunked-upload routes automatically (controlled by
 * config('updates.routes')). Publish this file with the `update-routes` tag if
 * you need full control over middleware, prefix, or naming, then set
 * `updates.routes.enabled` to false in config/updates.php and include this file
 * from your routes/web.php:
 *
 *     require base_path('routes/updates.php');
 */

use Illuminate\Support\Facades\Route;
use InstallerToolkit\Update\Http\ChunkedUploadController;

Route::group([
    'prefix' => 'system-update/upload',
    'middleware' => ['web', 'auth', 'verified'],
], function (): void {
    Route::post('initialize', [ChunkedUploadController::class, 'initialize'])->name('chunked-upload.initialize');
    Route::post('chunk', [ChunkedUploadController::class, 'chunk'])->name('chunked-upload.chunk');
    Route::post('assemble', [ChunkedUploadController::class, 'assemble'])->name('chunked-upload.assemble');
});
