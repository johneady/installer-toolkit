<?php

// ============================================================================
// Run the updater
// ============================================================================
// renderFatalErrorPage() is the shared fatal handler inlined from
// templates/shared/functions.php by bin/build.

try {
    (new Updater)->run();
} catch (Throwable $e) {
    renderFatalErrorPage(
        $e,
        'Application Updater',
        defined('UPDATER_VERSION') ? UPDATER_VERSION : 'unknown',
        defined('APP_NAME') ? APP_NAME : ''
    );
}
