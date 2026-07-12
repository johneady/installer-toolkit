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
    | Updater Authorization
    |--------------------------------------------------------------------------
    |
    | Controls who may launch the standalone updater — i.e. who may mint a
    | handoff token and be redirected to public/updater.php. A callable
    | (Closure, [Class, method] array, or "Class@method") invoked through the
    | container with the incoming Illuminate\Http\Request, returning true to
    | authorize. When null, the default guard requires an authenticated user
    | whose `is_admin` flag is truthy.
    |
    */

    'authorize_upload' => null,

    /*
    |--------------------------------------------------------------------------
    | Standalone Updater
    |--------------------------------------------------------------------------
    |
    | The self-update engine lives in public/updater.php — a framework-free
    | script assembled from templates/update and shipped inside every .update
    | package. This block configures how the application hands an admin off
    | to it.
    |
    |   path           — the public file the updater is served from.
    |   storage_dir    — where the updater keeps its handoff token, backups,
    |                    and results, relative to the project root. The
    |                    framework-free updater reads the same value, so this
    |                    is the single source of truth for that location.
    |   handoff_ttl    — seconds a minted launch token stays valid. 0 disables
    |                    expiry (still single-use: the updater deletes the
    |                    token file the moment it authorizes a session).
    |   launch_path    — the URI the package registers that mints a token and
    |                    redirects to the updater. Named route: updater.launch.
    |   middleware     — middleware on the launch route. Override to use a
    |                    non-default guard, e.g. ['web', 'auth:admin'].
    |   recent_results — how many recent outcomes the launch page renders.
    |
    */

    'updater' => [
        'enabled' => true,
        'path' => 'updater.php',
        'storage_dir' => 'storage/app/updater',
        'handoff_ttl' => 300,
        'launch_path' => 'system-update/launch',
        'middleware' => ['web', 'auth'],
        'recent_results' => 5,
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
