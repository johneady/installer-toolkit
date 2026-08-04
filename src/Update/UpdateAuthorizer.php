<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Http\Request;

/**
 * Resolves who may launch the standalone updater.
 *
 * Driven by config('updates.authorize_upload'): a Closure, [Class, method]
 * array, or "Class@method" string, invoked through the container so the
 * incoming Request (and any other dependencies) are injected. When null, the
 * default guard requires an authenticated user whose is_admin flag is truthy.
 *
 * Extracted from the old ChunkedUploadController so the slim launch flow and
 * the artisan commands share one authorization contract — and so it is
 * unit-tested rather than buried in a published stub.
 */
class UpdateAuthorizer
{
    /**
     * Resolve whether the request's user may launch the updater.
     */
    public function allows(Request $request): bool
    {
        $authorizer = config('updates.authorize_upload');

        if ($authorizer !== null) {
            return (bool) app()->call($authorizer, ['request' => $request]);
        }

        // The host app's User model exposes its admin flag as an Eloquent
        // attribute (column) rather than a declared property, so access it
        // via data_get() — that reads the attribute without a static type
        // dependency on a column this package can't know about. Null when
        // no user is authenticated.
        return (bool) data_get($request->user(), 'is_admin');
    }
}
