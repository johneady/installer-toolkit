<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Standalone update script for shared hosting without SSH access.
 *
 * Boots the Laravel kernel and runs migrations, the storage symlink, cache
 * clearing, and optimization using the command lists configured in
 * config/updates.php (johneady/installer-toolkit).
 *
 * Access is token-gated: on first visit this script writes a one-time token
 * to storage/app/update-php-token.txt (not web-accessible) and asks you to
 * retrieve it via your hosting file manager and re-visit with ?token=...
 * Only someone with filesystem access — the operator — can complete that
 * loop, so a stray copy of this file is not an open Artisan endpoint.
 *
 * ⚠️ DELETE THIS FILE AFTER A SUCCESSFUL UPDATE ANYWAY. The token guard is
 *    a safety net, not a reason to leave privileged scripts lying around.
 */
$tokenFile = __DIR__.'/../storage/app/update-php-token.txt';
$providedToken = isset($_GET['token']) ? (string) $_GET['token'] : '';

if (! file_exists($tokenFile)) {
    $token = bin2hex(random_bytes(32));

    if (@file_put_contents($tokenFile, $token) === false) {
        http_response_code(500);
        echo '<h2>Cannot write the access token</h2>';
        echo '<p>This script could not write <code>storage/app/update-php-token.txt</code>. Check that the <code>storage/app</code> directory is writable, then reload this page.</p>';
        exit;
    }

    @chmod($tokenFile, 0600);

    http_response_code(403);
    echo '<h2>Access token required</h2>';
    echo '<p>To prove you are the site operator, an access token has been written to:</p>';
    echo '<p><code>storage/app/update-php-token.txt</code></p>';
    echo '<ol>';
    echo '<li>Open that file with your hosting file manager (or FTP) and copy its contents.</li>';
    echo '<li>Re-visit this page as <code>update.php?token=PASTE-THE-TOKEN-HERE</code>.</li>';
    echo '</ol>';
    echo '<p>Only someone with filesystem access to this server can read that file, which is the point.</p>';
    exit;
}

$expectedToken = trim((string) file_get_contents($tokenFile));

if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo '<h2>Invalid or missing token</h2>';
    echo '<p>Append <code>?token=...</code> with the value from <code>storage/app/update-php-token.txt</code>. Delete that file and reload this page to generate a fresh token.</p>';
    exit;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$appName = config('app.name', 'Application');

echo '<h2>Running Updates for '.$appName.'...</h2>';

echo '<p><strong>Step 1:</strong> Running database migrations...</p>';
$kernel->call('migrate', ['--force' => true]);
echo "<p style='color: green;'>&#9989; Migrations completed!</p>";

echo '<p><strong>Step 2:</strong> Creating storage symlink...</p>';
try {
    $kernel->call('storage:link');
    echo "<p style='color: green;'>&#9989; Storage symlink created!</p>";
} catch (Throwable $e) {
    echo "<p style='color: orange;'>&#9888; Storage symlink skipped: {$e->getMessage()}</p>";
}

$clearCommands = config('updates.clear_cache_commands', ['config:clear', 'route:clear', 'view:clear', 'event:clear']);
echo '<p><strong>Step 3:</strong> Clearing caches...</p>';
foreach ($clearCommands as $command) {
    $kernel->call($command);
}
echo "<p style='color: green;'>&#9989; Caches cleared!</p>";

$optimizeCommands = config('updates.optimize_commands', ['config:cache', 'route:cache', 'view:cache', 'event:cache']);
echo '<p><strong>Step 4:</strong> Optimizing...</p>';
foreach ($optimizeCommands as $command) {
    try {
        $kernel->call($command);
    } catch (Throwable $e) {
        echo "<p style='color: orange;'>&#9888; {$command} skipped: {$e->getMessage()}</p>";
    }
}
echo "<p style='color: green;'>&#9989; Optimized!</p>";

// The token is single-update: a future run must mint (and have the operator
// fetch) a fresh one.
@unlink($tokenFile);

echo '<hr>';
echo "<h2 style='color: green;'>&#9989; Update Complete!</h2>";
echo "<p style='color: red; font-size: 18px; font-weight: bold;'>&#9888; DELETE THIS FILE IMMEDIATELY!</p>";
