<?php
// ============================================================
// Shared Hosting Update Guide — johneady/installer-toolkit
// ============================================================
// This is a self-contained compatibility checker + update guide for shared
// hosting environments without SSH access. It deliberately does NOT boot
// Laravel so it still works on a partially-broken install.
//
// Project name / folder auto-detect from the environment or the parent
// directory name; override at the top here if the detection is wrong.

$projectFolder = getenv('UPDATE_SLUG') ?: basename(dirname(__DIR__, 1));
$projectName = getenv('APP_NAME') ?: ucfirst(str_replace(['-', '_'], ' ', $projectFolder));
$minPhp = getenv('UPDATE_MIN_PHP') ?: '8.2.0';

// Handle file download request
if (isset($_GET['download']) && $_GET['download'] == '1') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="shared_update.php"');
    header('Content-Length: '.filesize(__FILE__));
    readfile(__FILE__);
    exit;
}

// ===============================
// Requirements for Shared Hosting
// ===============================
$requirements = [
    'PHP >= '.$minPhp => version_compare(PHP_VERSION, $minPhp, '>='),
    'Ctype Extension' => extension_loaded('ctype'),
    'cURL Extension' => extension_loaded('curl'),
    'DOM Extension' => extension_loaded('dom'),
    'Fileinfo Extension' => extension_loaded('fileinfo'),
    'Filter Extension' => extension_loaded('filter'),
    'Hash Extension' => extension_loaded('hash'),
    'JSON Extension' => extension_loaded('json'),
    'Mbstring Extension' => extension_loaded('mbstring'),
    'OpenSSL Extension' => extension_loaded('openssl'),
    'PCRE Extension' => extension_loaded('pcre'),
    'PDO Extension' => extension_loaded('pdo'),
    'Session Extension' => extension_loaded('session'),
    'Tokenizer Extension' => extension_loaded('tokenizer'),
    'XML Extension' => extension_loaded('xml'),
    'Zip Extension' => extension_loaded('zip'),
    'GD Extension (or Imagick)' => extension_loaded('gd') || extension_loaded('imagick'),
    'POSIX Extension' => extension_loaded('posix'),
];

// ============================
// Scheduler Specific Functions
// ============================
$functions = ['proc_open', 'proc_get_status'];
$disabled = explode(',', ini_get('disable_functions'));

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Hosting Update Guide - <?php echo $projectName; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
        h1 { color: #2563eb; margin-bottom: 10px; font-size: 32px; }
        h2 { color: #1e40af; margin-top: 40px; margin-bottom: 20px; font-size: 24px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
        h3 { color: #374151; margin-top: 25px; margin-bottom: 15px; font-size: 18px; }
        .intro { background: #eff6ff; border-left: 4px solid #2563eb; padding: 20px; margin: 30px 0; border-radius: 4px; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .warning strong { color: #92400e; }
        .success { background: #d1fae5; border-left: 4px solid #10b981; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .step { background: #f9fafb; border: 1px solid #e5e7eb; padding: 20px; margin: 20px 0; border-radius: 6px; }
        .step-number { display: inline-block; background: #2563eb; color: white; width: 30px; height: 30px; border-radius: 50%; text-align: center; line-height: 30px; font-weight: bold; margin-right: 10px; }
        code { background: #1f2937; color: #10b981; padding: 2px 8px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 14px; }
        pre { background: #1f2937; color: #e5e7eb; padding: 20px; border-radius: 6px; overflow-x: auto; margin: 15px 0; border: 1px solid #374151; }
        pre code { background: transparent; padding: 0; color: #10b981; }
        ul, ol { margin-left: 30px; margin-top: 10px; margin-bottom: 10px; }
        li { margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; color: #1f2937; }
        tr:nth-child(even) { background: #f9fafb; }
        .note { font-style: italic; color: #6b7280; font-size: 14px; margin-top: 10px; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .compatibility-check { background: #f0fdf4; border: 2px solid #10b981; padding: 25px; margin: 30px 0; border-radius: 6px; }
        .compatibility-check h2 { color: #065f46; margin-top: 0; border-bottom: none; font-size: 24px; }
        .compatibility-check h3 { color: #047857; margin-top: 20px; }
        .compatibility-result { font-size: 15px; line-height: 2; font-family: 'Courier New', monospace; }
        .compat-note { background: white; padding: 15px; margin-top: 20px; border-radius: 4px; border-left: 4px solid #2563eb; font-style: italic; color: #4b5563; }
        .server-notice { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; margin: 0 0 30px 0; border-radius: 8px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); }
        .server-notice h2 { color: white; margin: 0 0 15px 0; border-bottom: 2px solid rgba(255, 255, 255, 0.3); padding-bottom: 10px; font-size: 24px; }
        .server-notice p { margin: 10px 0; font-size: 16px; line-height: 1.6; }
        .server-notice strong { font-weight: 700; text-decoration: underline; }
        .download-btn { display: inline-block; background: white; color: #667eea; padding: 12px 24px; border-radius: 6px; font-weight: 600; text-decoration: none; margin-top: 15px; transition: transform 0.2s, box-shadow 0.2s; }
        .download-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); text-decoration: none; }
        .download-btn::before { content: '\2B07\FE0F '; font-size: 18px; }
    </style>
</head>

<body>
    <div class="container">
        <div class="server-notice">
            <h2>Test your shared hosting provider</h2>
            <p><strong>Important:</strong> Before proceeding with the upgrade, upload this compatibility checker to your shared hosting server. This guide assumes shared hosting (no SSH access); alternative methods are provided below.</p>
            <p>Upload <code>shared_update.php</code> to your application's public folder and access it at <code>https://yourdomain.com/shared_update.php</code> to verify your server meets all requirements. Replace the copy that shipped with the original install with this version.</p>
            <a href="?download=1" class="download-btn">Download latest shared_update.php</a>
        </div>

        <h1><?php echo $projectName; ?></h1>
        <p style="color: #6b7280; font-size: 18px;">Shared Hosting Update Guide</p>

        <div class="intro">
            <p><strong>Welcome!</strong> This guide walks you through updating <?php echo $projectName; ?> on shared hosting. You should have already received an update package — a file named like <code><?php echo $projectFolder; ?>-v2.1.0.update</code>.</p>
        </div>

        <div class="success">
            <h3>Recommended: use the built-in updater</h3>
            <p>If you can log in to your admin panel, you don't need this manual guide at all: go to <strong>System Update</strong> in the admin area, upload the <code>.update</code> file, and the application backs itself up, validates the package, and applies the update for you. Use the manual steps below only if the admin panel is unreachable.</p>
        </div>

        <div class="compatibility-check">
            <h2>Application Compatibility Check</h2>
            <div class="compatibility-result">
                <?php foreach ($requirements as $label => $met) { ?>
                    <?php echo ($met ? '&#9989;' : '&#10060;').' '.$label; ?> <br>
                <?php } ?>

                <h3>Scheduler Support</h3>
                <?php foreach ($functions as $func) { ?>
                    <?php
                    $isAvailable = ! in_array($func, array_map('trim', $disabled)) && function_exists($func);
                    echo ($isAvailable ? '&#9989;' : '&#10060;').' Function: '.$func;
                    ?> <br>
                <?php } ?>
            </div>
            <div class="compat-note">
                <strong>Live Test:</strong> This compatibility check runs in real time. Items marked &#9989; are available. Any items marked &#10060; must be enabled or installed by your hosting provider before proceeding.
                <br><br>
                <strong>Server Details:</strong>
                <br>&bull; PHP Version: <code><?php echo PHP_VERSION; ?></code>
                <br>&bull; Server Software: <code><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></code>
                <br>&bull; Operating System: <code><?php echo PHP_OS; ?></code>
                <br>&bull; Server Name: <code><?php echo $_SERVER['SERVER_NAME'] ?? 'Unknown'; ?></code>
            </div>
        </div>

        <h2>Step 1: Prepare for the Update</h2>

        <div class="step">
            <p><span class="step-number">1</span><strong>Backup your current installation</strong></p>
            <p>Before making any changes, create a complete backup:</p>
            <ol>
                <li>Backup your database via phpMyAdmin or your hosting control panel</li>
                <li>Download a copy of your entire application directory</li>
                <li>Backup your <code>.env</code> file separately</li>
                <li>Backup any custom files or modifications you've made</li>
            </ol>
        </div>

        <div class="step">
            <p><span class="step-number">2</span><strong>Enable maintenance mode</strong></p>
            <p>Create a file called <code>down.php</code> in your public directory:</p>

            <pre><code>&lt;?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->call('down');
echo 'Maintenance mode enabled. DELETE THIS FILE AFTER UPDATE!';</code></pre>

            <p>Visit <code>https://yourdomain.com/down.php</code> to enable maintenance mode, then delete the file.</p>
        </div>

        <h2>Step 2: Upload the Updated Files</h2>

        <div class="step">
            <p><span class="step-number">3</span><strong>Extract the update package on your computer</strong></p>
            <p>The <code><?php echo $projectFolder; ?>-vX.Y.Z.update</code> file you received is a ZIP archive. Rename it to end in <code>.zip</code>, then unzip it. Inside you'll find <code>manifest.json</code> and <code><?php echo $projectFolder; ?>.zip</code> — unzip that inner file too. The updated application files are in the <code><?php echo $projectFolder; ?>/</code> folder it produces; those are what you upload in the next steps.</p>
        </div>

        <div class="step">
            <p><span class="step-number">4</span><strong>Connect to your hosting via FTP or File Manager</strong></p>
            <p>Use an FTP client (FileZilla, Cyberduck) or your hosting control panel's file manager.</p>
        </div>

        <div class="step">
            <p><span class="step-number">5</span><strong>Upload the updated files</strong></p>
            <p>Upload the contents of the extracted <code><?php echo $projectFolder; ?>/</code> folder to your application directory, overwriting existing files:</p>
            <ul>
                <li>Upload everything to <code>/home/username/<?php echo $projectFolder; ?>/</code> (or your custom location)</li>
                <li>Do NOT overwrite your <code>.env</code> file</li>
                <li>Do NOT overwrite your <code>storage/</code> directory</li>
                <li>Do NOT overwrite any custom files you've created</li>
            </ul>
        </div>

        <h2>Step 3: Run Update Script</h2>

        <div class="step">
            <p><span class="step-number">6</span><strong>Run the update script</strong></p>
            <ol>
                <li>Upload <code>update.php</code> (provided alongside this guide) to your <code>public/</code> directory and visit <code>https://yourdomain.com/update.php</code> in your browser</li>
                <li>The script writes an access token to <code>storage/app/update-php-token.txt</code> — open that file with your hosting file manager, copy the token, and re-visit as <code>update.php?token=YOUR-TOKEN</code></li>
                <li>Wait for all steps to complete (migrations, storage symlink, cache clearing, and optimizations)</li>
                <li><strong>DELETE the update.php file immediately after completion</strong></li>
            </ol>
            <p class="note">The token step proves you have filesystem access to the server, so the script can't be triggered by random visitors. update.php handles migrations, cache clearing, and optimizations automatically.</p>
        </div>

        <h2>Step 4: Disable Maintenance Mode</h2>

        <div class="step">
            <p><span class="step-number">7</span><strong>Create an up.php script</strong></p>
            <p>Create <code>public/up.php</code>:</p>

            <pre><code>&lt;?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->call('up');
echo 'Application is now live! DELETE THIS FILE!';</code></pre>

            <p>Visit <code>https://yourdomain.com/up.php</code> to disable maintenance mode, then delete the file.</p>
        </div>

        <h2>Step 5: Verify the Update</h2>

        <div class="step">
            <p><span class="step-number">8</span><strong>Test your updated installation</strong></p>
            <ol>
                <li>Visit your domain: <code>https://yourdomain.com</code></li>
                <li>Verify the homepage loads correctly</li>
                <li>Test user registration and login</li>
                <li>Check that all pages load without errors</li>
                <li>Verify cron jobs are still running (check scheduled features)</li>
                <li>Test any new features included in the update</li>
            </ol>
        </div>

        <div class="step">
            <p><span class="step-number">9</span><strong>Check for common issues</strong></p>

            <h3>500 Internal Server Error</h3>
            <ul>
                <li>Check storage and bootstrap/cache permissions (775)</li>
                <li>Verify .htaccess exists and is correct</li>
                <li>Check that APP_KEY is set in .env</li>
                <li>Review error logs in your hosting control panel</li>
            </ul>

            <h3>404 Errors on all pages except homepage</h3>
            <ul>
                <li>Ensure mod_rewrite is enabled</li>
                <li>Verify .htaccess is being read</li>
                <li>Check that AllowOverride is set correctly on the server</li>
            </ul>

            <h3>Database connection errors</h3>
            <ul>
                <li>Verify database credentials in .env</li>
                <li>Ensure the database user has proper privileges</li>
                <li>Check DB_HOST (might be 'localhost' or an IP address)</li>
            </ul>
        </div>

        <div class="success">
            <h3>Update Complete!</h3>
            <p>Your <?php echo $projectName; ?> should now be fully updated. Remember to:</p>
            <ul>
                <li>Delete all temporary update scripts (update.php, up.php, down.php)</li>
                <li>Verify the cron job is still running every minute</li>
                <li>Keep your .env file secure</li>
                <li>Regularly backup your database and files</li>
                <li>Monitor error logs for any issues</li>
            </ul>
        </div>

        <div class="warning">
            <strong>Important:</strong> Always delete temporary PHP scripts after use. They pose a security risk if left accessible.
        </div>

        <h2>Quick Reference</h2>

        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>File Location</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Application Root</td><td><code>/home/username/<?php echo $projectFolder; ?>/</code></td></tr>
                <tr><td>Public Files</td><td><code>/home/username/public_html/</code></td></tr>
                <tr><td>Environment Config</td><td><code>/home/username/<?php echo $projectFolder; ?>/.env</code></td></tr>
                <tr><td>Storage (logs, cache)</td><td><code>/home/username/<?php echo $projectFolder; ?>/storage/</code></td></tr>
                <tr><td>Cron Command</td><td><code>/usr/bin/php artisan schedule:run</code></td></tr>
            </tbody>
        </table>

        <div class="note" style="margin-top: 40px; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 20px;">
            This guide was created for <?php echo $projectName; ?>. For additional support, consult your hosting provider's documentation or contact their support team.
        </div>
    </div>
</body>

</html>
