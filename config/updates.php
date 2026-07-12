<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Slug
    |--------------------------------------------------------------------------
    |
    | The unique identifier for this application. This MUST match the `slug`
    | defined in package/package-config.php, because the inner zip inside a
    | .update package is named `{slug}.zip` and its files are prefixed with
    | `{slug}/`. package-config.php is excluded from customer installs by the
    | builder, so the runtime value comes from here.
    |
    */

    'slug' => env('UPDATE_SLUG', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Protected Paths
    |--------------------------------------------------------------------------
    |
    | Relative paths (from the project root) that must never be overwritten
    | during an update. Directory entries must end with a trailing slash to
    | protect everything beneath them.
    |
    | Do NOT add 'database/' here: new database/migrations/*.php files must
    | be extracted or the update's migrate step silently applies nothing.
    | SQLite data files under database/ are already protected by a built-in
    | rule in UpdateService::isProtectedPath().
    |
    */

    'protected_paths' => [
        '.env',
        'storage/app/license.key',
        'storage/app/public/',
        'storage/logs/',
        'storage/framework/sessions/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction
    |--------------------------------------------------------------------------
    |
    | `extraction_batch_size` is how many files are extracted per HTTP request
    | during the batched extraction step. Lower this on memory-constrained
    | shared hosts.
    |
    */

    'extraction_batch_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Upload Authorization
    |--------------------------------------------------------------------------
    |
    | Controls who may upload a .update package. A callable (Closure,
    | [Class, method] string array, or "Class@method") that receives the
    | incoming Illuminate\Http\Request and returns true to authorize the
    | upload. When null, the default guard requires an authenticated user
    | whose `is_admin` flag is truthy.
    |
    */

    'authorize_upload' => null,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The toolkit registers its own chunked-upload routes. Disable them here
    | (and publish the routes stub) if you need full control over middleware,
    | prefix, or naming. Route names are always `<name>.{initialize,chunk,
    | assemble}` so the Blade view can resolve them.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'system-update/upload',
        'middleware' => ['web', 'auth'],
        'name' => 'chunked-upload',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache & Optimize Commands
    |
    | Artisan commands run during the `clear_cache` and `optimize` steps.
    | Commands that fail during `optimize` are recorded as warnings rather
    | than aborting the update.
    |
    */

    'clear_cache_commands' => [
        'config:clear',
        'route:clear',
        'view:clear',
        'event:clear',
    ],

    'optimize_commands' => [
        'config:cache',
        'route:cache',
        'view:cache',
        'event:cache',
        'icons:cache',
        'filament:optimize',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup & Rollback
    |--------------------------------------------------------------------------
    |
    | Before extracting an update, the toolkit snapshots the current app files
    | (and optionally the database) so a failed update can be rolled back.
    | Backups live under storage/app/{directory}/{id}/.
    |
    | `include_vendor`: vendor/ is large; include it for a faithful rollback,
    | exclude it to save time/space if you accept that composer dependencies
    | will not be rolled back.
    |
    */

    'backup' => [
        'enabled' => env('UPDATE_BACKUP_ENABLED', true),
        'directory' => 'update-backups',
        'keep' => 3,
        'include_vendor' => true,
        'exclude' => [
            'node_modules',
            '.git',
            'storage/framework',
            'storage/logs',
            'storage/app/update-backups',
            'storage/app/update-staging-*',
            'storage/app/pending-update-*',
            'package',
            'tests',
        ],
        'database' => [
            'enabled' => true,
            'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Abandoned upload artifacts (pending zips, staging dirs, progress files)
    | older than this many hours are deleted by the `update:prune` command.
    |
    */

    'prune_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Update Signing
    |--------------------------------------------------------------------------
    |
    | Ed25519 public keys trusted to sign .update packages, keyed by key_id
    | (see `manifest.json`'s `key_id` field). Verification is fail-closed and
    | self-activating:
    |
    |   - Empty array: signatures are NOT required. Only use this for an
    |     app that has never shipped signed updates and has existing
    |     installs that must keep accepting unsigned packages.
    |   - Non-empty array (the shipped default): every update MUST carry a
    |     `signature` and `key_id` in its manifest, the `key_id` must match
    |     an entry here, and the signature must verify. Anything else is
    |     rejected.
    |
    | Because a key ships here by default, every app consuming this package
    | must also set `signing_key_id` in its package/package-config.php (and
    | provide UPDATE_SIGNING_KEY at build time) so package:build produces
    | signed .update files — an unsigned build would be rejected by the
    | app's own installs.
    |
    | To rotate: add the new key_id/key here (old key stays, so in-flight
    | updates signed with it still verify), release at least one update
    | signed with the new key, then remove the old key_id in a later release.
    |
    | Generate a keypair with `php artisan update:keygen`.
    |
    */

    'signing' => [
        'trusted_keys' => [
            'key-2026-07' => 'gkYr9tN8qAfbY5qQTc+rbh9cUYb9r10mujwZ5tPzo/o=',
            'key-2026-07-b' => 'BqglICOiaFr+pu2siQu0hc+AgXdsMs+G0/yAvren5Fc=',
        ],
    ],

];
