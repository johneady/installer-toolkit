<?php

/**
 * Web Updater
 *
 * A standalone, self-contained update tool for PHP applications distributed
 * as .update packages. It lives at public/updater.php inside the installed
 * application, is laid down by the installer, and is refreshed by every
 * update it applies.
 *
 * It deliberately does NOT load the application's framework or vendor/
 * directory to do its work: uploading, validating, backing up, and
 * extracting all run on plain PHP, so a broken or half-updated application
 * can never take the updater down with it. The framework is booted exactly
 * once — via the package's own post-update hook — only after every file is
 * in place.
 *
 * Access is token-gated. The admin panel hands off a one-time token when it
 * launches the updater; without one, the updater falls back to a
 * filesystem-proof token (written to storage/, readable only by the site
 * operator), which also makes this the recovery tool for a broken install.
 */

// ============================================================================
// DO NOT TOUCH ANY CODE BELOW
// ============================================================================

// The values below are placeholders. bin/build replaces this entire block
// with real values from the consuming app's package/package-config.php
// (app folder/slug, app name, min_php_version) plus the toolkit's own
// UPDATER_VERSION every time it generates that app's updater.php —
// editing them here has no effect on any deployed app.
// [[INSTALLER_CONFIG]]
define('APP_FOLDER', 'Generated at build time');
define('APP_NAME', 'Generated at build time');
define('MIN_PHP_VERSION', 'Generated at build time');
define('UPDATER_VERSION', 'Generated at build time');
// [[/INSTALLER_CONFIG]]

// updater.php lives in {app}/public/, so the application root is one up.
define('UPDATER_APP_ROOT', dirname(__DIR__));

// ============================================================================
// env() polyfill
// ============================================================================
// The application's config/updates.php (and config/app.php) call env(),
// which only exists once Laravel's helpers are loaded — and loading those
// would defeat the whole point of a framework-free updater. This polyfill
// lets the updater `require` those config files directly. It reads from
// $_ENV/$_SERVER first (so when the post-update hook later boots Laravel and
// Dotenv populates them, values stay correct), then from the .env values the
// updater parsed itself, then getenv(). Laravel's own env() helper is
// guarded by function_exists(), so defining it here is safe: Laravel simply
// keeps using this one, with matching semantics for quoted values and the
// true/false/null/empty literals.

$GLOBALS['__updater_env'] = [];

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? $GLOBALS['__updater_env'][$key] ?? null;

        if ($value === null) {
            $fromGetenv = getenv($key);
            $value = $fromGetenv === false ? null : $fromGetenv;
        }

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            return $value;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        if (strlen($value) > 1 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

// ============================================================================
// Updater Class
// ============================================================================
