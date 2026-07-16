<?php

// ============================================================================
// Run the updater
// ============================================================================
// renderFatalErrorPage() is the shared fatal handler inlined from
// templates/shared/functions.php by bin/build.

$updater = null;

try {
    $updater = new Updater;
    $updater->run();
} catch (Throwable $e) {
    // An unauthenticated visitor must not see exception detail (paths,
    // stack trace) for a live production site — only a session that has
    // passed the token gate is entitled to it. $updater may be null (the
    // exception happened during construction, before a session could even
    // be started), which is exactly the "not authorized" case.
    renderFatalErrorPage(
        $e,
        'Application Updater',
        defined('UPDATER_VERSION') ? UPDATER_VERSION : 'unknown',
        defined('APP_NAME') ? APP_NAME : '',
        $updater?->isAuthorized() ?? false
    );
}
