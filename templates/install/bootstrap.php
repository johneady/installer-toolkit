<?php

// ============================================================================
// Run the installer
// ============================================================================
// renderFatalErrorPage() is the shared fatal handler inlined from
// templates/shared/functions.php by bin/build.

try {
    (new Installer)->run();
} catch (Throwable $e) {
    // install.php has no authorized/unauthorized distinction — running the
    // wizard at all is inherently pre-auth (that's the installer's own
    // security model, unrelated to this gate), so full detail is always
    // shown here, same as before this gate existed for updater.php.
    renderFatalErrorPage(
        $e,
        'Application Installer',
        defined('INSTALLER_VERSION') ? INSTALLER_VERSION : 'unknown',
        defined('APP_NAME') ? APP_NAME : '',
        isAuthorized: true
    );
}
