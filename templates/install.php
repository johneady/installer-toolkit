<?php

/**
 * Web Installer
 *
 * A standalone, self-contained installation wizard for Laravel applications.
 * Upload this file alongside the accompanying zip to your web server's document root
 * and visit this file in your browser to begin the installation.
 *
 * www.yourdomain.com/install.php
 */

// ============================================================================
// Configuration Constants - DO NOT TOUCH ANY CODE BELOW
// ============================================================================

// The values below are placeholders. bin/build replaces this entire block
// with real values from the consuming app's package/package-config.php
// (zip filename, app folder, min_php_version) every time it generates that
// app's install.php — editing them here has no effect on any deployed app.
// [[INSTALLER_CONFIG]]
define('ZIP_FILENAME', 'Generated at build time');
define('APP_FOLDER', 'Generated at build time');
define('MIN_PHP_VERSION', 'Generated at build time');
// [[/INSTALLER_CONFIG]]

define('EULA_TEXT', <<<'EULA'
END USER LICENSE AGREEMENT (EULA)

IMPORTANT: PLEASE READ THIS LICENSE AGREEMENT CAREFULLY BEFORE INSTALLING OR USING THIS SOFTWARE.

By installing, copying, or otherwise using this software ("Software"), you agree to be bound by the terms of this End User License Agreement ("Agreement"). If you do not agree to these terms, do not install or use the Software.

1. LICENSE GRANT
The licensor grants you a non-exclusive, non-transferable license to install and use the Software on a single website or server for your personal or business purposes, subject to the terms of this Agreement.

2. RESTRICTIONS
You may not:
(a) Redistribute, sublicense, sell, lease, or otherwise transfer the Software to any third party.
(b) Modify, reverse engineer, decompile, or disassemble the Software except as permitted by applicable law.
(c) Remove or alter any proprietary notices, labels, or marks on the Software.
(d) Use the Software to operate a service bureau or provide hosting services to third parties.

3. OWNERSHIP
The Software is licensed, not sold. The licensor retains all rights, title, and interest in and to the Software, including all intellectual property rights.

4. DISCLAIMER OF WARRANTIES
THE SOFTWARE IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NONINFRINGEMENT. THE LICENSOR DOES NOT WARRANT THAT THE SOFTWARE WILL BE ERROR-FREE OR UNINTERRUPTED.

5. LIMITATION OF LIABILITY
IN NO EVENT SHALL THE LICENSOR BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING BUT NOT LIMITED TO LOSS OF PROFITS, DATA, OR USE, ARISING OUT OF OR IN CONNECTION WITH THIS AGREEMENT OR THE USE OF THE SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGES. THE LICENSOR'S TOTAL LIABILITY SHALL NOT EXCEED THE AMOUNT PAID FOR THE SOFTWARE.

6. INDEMNIFICATION
You agree to indemnify and hold harmless the licensor from any claims, damages, losses, or expenses arising from your use of the Software or your breach of this Agreement.

7. SUPPORT AND UPDATES
The licensor is not obligated to provide support, maintenance, or updates for the Software unless separately agreed upon. Any updates provided shall be subject to this Agreement.

8. TERMINATION
This Agreement is effective until terminated. The licensor may terminate this Agreement immediately if you breach any of its terms. Upon termination, you must cease all use of the Software and destroy all copies.

9. GOVERNING LAW
This Agreement shall be governed by and construed in accordance with the laws of the jurisdiction in which the licensor resides, without regard to its conflict of law provisions.

10. ENTIRE AGREEMENT
This Agreement constitutes the entire agreement between you and the licensor regarding the Software and supersedes all prior agreements, understandings, and communications.

By proceeding with the installation, you acknowledge that you have read, understood, and agree to be bound by this Agreement.
EULA
);

// ============================================================================
// Installer Class
// ============================================================================

class Installer
{
    private array $errors = [];

    private int $totalSteps = 9;

    private array $stepNames = [
        1 => 'License',
        2 => 'Requirements',
        3 => 'Settings',
        7 => 'Install',
        8 => 'Cron Job',
        9 => 'Complete',
    ];

    private array $settingsSubSteps = [3, 4, 5, 6];

    private array $settingsSubStepNames = [
        3 => 'Database',
        4 => 'Application',
        5 => 'Email',
        6 => 'Admin Account',
    ];

    public function run(): void
    {
        session_start();

        // Initialize session storage
        if (! isset($_SESSION['installer'])) {
            $_SESSION['installer'] = [];
        }

        $step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
        $step = max(1, min($this->totalSteps, $step));

        // Handle installation task reset (retry from scratch)
        if ($step === 7 && isset($_GET['reset']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $_SESSION['installer']['completed_tasks'] = [];
            unset($_SESSION['installer']['extract_offset']);
            @unlink(__DIR__.'/.install-working.zip');
            @unlink(__DIR__.'/'.APP_FOLDER.'/public/install-optimize.php');
            echo json_encode(['success' => true]);
            exit;
        }

        // Handle AJAX requests for step 7
        if ($step === 7 && isset($_GET['task']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleInstallTask($_GET['task']);

            return;
        }

        // Handle AJAX database test
        if ($step === 3 && isset($_GET['action']) && $_GET['action'] === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleDatabaseTest();

            return;
        }

        // Handle AJAX mail test
        if ($step === 5 && isset($_GET['action']) && $_GET['action'] === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleMailTest();

            return;
        }

        // Already-installed guard — allow steps 7-8 through when the
        // current session completed the installation (the .env now has an
        // APP_KEY so isAlreadyInstalled() would trigger prematurely).
        if ($this->isAlreadyInstalled() && empty($_SESSION['installer']['install_complete'])) {
            $this->renderAlreadyInstalled();

            return;
        }

        // Validate step access (can't skip ahead)
        $step = $this->validateStepAccess($step);

        // Handle POST submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($step);

            return;
        }

        // Render the current step
        $this->renderStep($step);
    }

    // ========================================================================
    // Already-Installed Check
    // ========================================================================

    private function isAlreadyInstalled(): bool
    {
        $envFile = __DIR__.'/'.APP_FOLDER.'/.env';
        if (! file_exists($envFile)) {
            return false;
        }

        $envContent = file_get_contents($envFile);

        return (bool) preg_match('/APP_KEY=base64:.+/', $envContent);
    }

    // ========================================================================
    // Step Access Validation
    // ========================================================================

    private function validateStepAccess(int $requestedStep): int
    {
        $data = $_SESSION['installer'];

        if ($requestedStep >= 2 && empty($data['eula_accepted'])) {
            return 1;
        }
        if ($requestedStep >= 3 && empty($data['requirements_passed'])) {
            return 2;
        }
        if ($requestedStep >= 4 && empty($data['db'])) {
            return 3;
        }
        if ($requestedStep >= 5 && empty($data['settings'])) {
            return 4;
        }
        if ($requestedStep >= 6 && empty($data['mail'])) {
            return 5;
        }
        if ($requestedStep >= 7 && empty($data['admin'])) {
            return 6;
        }
        if ($requestedStep >= 8 && empty($data['install_complete'])) {
            return 7;
        }

        return $requestedStep;
    }

    // ========================================================================
    // POST Handlers
    // ========================================================================

    private function handlePost(int $step): void
    {
        switch ($step) {
            case 1:
                $this->handleEulaPost();
                break;
            case 2:
                $this->handleRequirementsPost();
                break;
            case 3:
                $this->handleDatabasePost();
                break;
            case 4:
                $this->handleSettingsPost();
                break;
            case 5:
                $this->handleMailPost();
                break;
            case 6:
                $this->handleAdminPost();
                break;
            default:
                $this->renderStep($step);
        }
    }

    private function handleEulaPost(): void
    {
        if (empty($_POST['accept_eula'])) {
            $this->errors[] = 'You must accept the license agreement to continue.';
            $this->renderStep(1);

            return;
        }

        $_SESSION['installer']['eula_accepted'] = true;
        header('Location: install.php?step=2');
        exit;
    }

    private function handleRequirementsPost(): void
    {
        $results = $this->checkRequirements();
        $allCriticalPassed = true;
        foreach ($results as $result) {
            if (! $result['passed'] && $result['critical']) {
                $allCriticalPassed = false;
                break;
            }
        }

        if (! $allCriticalPassed) {
            $this->errors[] = 'Not all critical requirements are met.';
            $this->renderStep(2);

            return;
        }

        $_SESSION['installer']['requirements_passed'] = true;
        header('Location: install.php?step=3');
        exit;
    }

    private function handleDatabasePost(): void
    {
        $host = trim($_POST['db_host'] ?? '');
        $port = trim($_POST['db_port'] ?? '3306');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        if ($host === '' || $name === '' || $user === '') {
            $this->errors[] = 'Please fill in all required database fields.';
            $this->renderStep(3);

            return;
        }

        // Test connection
        try {
            new PDO(
                "mysql:host={$host};port={$port};dbname={$name}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (PDOException $e) {
            $this->errors[] = 'Database connection failed: '.$e->getMessage();
            $this->renderStep(3);

            return;
        }

        $_SESSION['installer']['db'] = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];

        header('Location: install.php?step=4');
        exit;
    }

    private function handleDatabaseTest(): void
    {
        header('Content-Type: application/json');

        $host = trim($_POST['db_host'] ?? '');
        $port = trim($_POST['db_port'] ?? '3306');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        if ($host === '' || $name === '' || $user === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
            exit;
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            echo json_encode(['success' => true, 'message' => "Connected successfully. MySQL version: {$version}"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Connection failed: '.$e->getMessage()]);
        }

        exit;
    }

    private function handleSettingsPost(): void
    {
        $appName = trim($_POST['app_name'] ?? '');
        $appUrl = trim($_POST['app_url'] ?? '');
        $timezone = trim($_POST['timezone'] ?? '');
        $sampleData = trim($_POST['sample_data'] ?? 'essential');

        if ($appName === '') {
            $this->errors[] = 'Application name is required.';
        }
        if ($appUrl === '') {
            $this->errors[] = 'Application URL is required.';
        }
        if ($timezone === '') {
            $this->errors[] = 'Timezone is required.';
        }

        if (! empty($this->errors)) {
            $this->renderStep(4);

            return;
        }

        // Ensure URL doesn't have trailing slash
        $appUrl = rtrim($appUrl, '/');

        $_SESSION['installer']['settings'] = [
            'app_name' => $appName,
            'app_url' => $appUrl,
            'timezone' => $timezone,
            'sample_data' => $sampleData,
        ];

        header('Location: install.php?step=5');
        exit;
    }

    private function handleMailPost(): void
    {
        $mailMailer = trim($_POST['mail_mailer'] ?? 'log');
        $mailHost = trim($_POST['mail_host'] ?? '');
        $mailPort = trim($_POST['mail_port'] ?? '587');
        $mailUsername = trim($_POST['mail_username'] ?? '');
        $mailPassword = $_POST['mail_password'] ?? '';
        $mailFromAddress = trim($_POST['mail_from_address'] ?? '');
        $mailFromName = trim($_POST['mail_from_name'] ?? '');

        // Fall back to admin email when using log driver and no from address is provided
        if ($mailFromAddress === '') {
            $mailFromAddress = $_SESSION['installer']['admin']['email'] ?? 'noreply@example.com';
        }

        if ($mailFromName === '') {
            $mailFromName = $_SESSION['installer']['settings']['app_name'] ?? ucwords(str_replace('-', ' ', APP_FOLDER));
        }

        $_SESSION['installer']['mail'] = [
            'mail_mailer' => $mailMailer,
            'mail_host' => $mailHost,
            'mail_port' => $mailPort,
            'mail_username' => $mailUsername,
            'mail_password' => $mailPassword,
            'mail_from_address' => $mailFromAddress,
            'mail_from_name' => $mailFromName,
        ];

        header('Location: install.php?step=6');
        exit;
    }

    private function handleMailTest(): void
    {
        header('Content-Type: application/json');

        $mailer = trim($_POST['mail_mailer'] ?? '');

        if ($mailer === 'log') {
            echo json_encode(['success' => true, 'message' => 'Log driver selected — no connection to test.']);
            exit;
        }

        if ($mailer === 'sendmail') {
            $sendmailPath = '/usr/sbin/sendmail';
            if (is_executable($sendmailPath)) {
                echo json_encode(['success' => true, 'message' => 'Sendmail binary found at '.$sendmailPath.'.']);
            } elseif (function_exists('mail')) {
                echo json_encode(['success' => true, 'message' => 'PHP mail() function is available.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sendmail binary not found and PHP mail() function is disabled. Email sending may not work.']);
            }
            exit;
        }

        if ($mailer === 'smtp') {
            $host = trim($_POST['mail_host'] ?? '');
            $port = (int) trim($_POST['mail_port'] ?? '587');

            if ($host === '') {
                echo json_encode(['success' => false, 'message' => 'Please enter an SMTP host.']);
                exit;
            }

            $errno = 0;
            $errstr = '';
            $connection = @fsockopen($host, $port, $errno, $errstr, 5);

            if ($connection) {
                $banner = @fgets($connection, 512);
                @fclose($connection);
                $banner = trim($banner);
                echo json_encode(['success' => true, 'message' => "Connected to {$host}:{$port}. Server response: {$banner}"]);
            } else {
                echo json_encode(['success' => false, 'message' => "Could not connect to {$host}:{$port}. Error: {$errstr} ({$errno})"]);
            }
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown mail driver.']);
        exit;
    }

    private function handleAdminPost(): void
    {
        $firstName = trim($_POST['admin_first_name'] ?? '');
        $lastName = trim($_POST['admin_last_name'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $passwordConfirm = $_POST['admin_password_confirm'] ?? '';

        if ($firstName === '') {
            $this->errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $this->errors[] = 'Last name is required.';
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $this->errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $passwordConfirm) {
            $this->errors[] = 'Passwords do not match.';
        }

        if (! empty($this->errors)) {
            $this->renderStep(6);

            return;
        }

        $_SESSION['installer']['admin'] = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
        ];

        header('Location: install.php?step=7');
        exit;
    }

    // ========================================================================
    // Requirements Check
    // ========================================================================

    private function checkRequirements(): array
    {
        $results = [];

        // PHP Version
        $results[] = [
            'name' => 'PHP Version >= '.MIN_PHP_VERSION,
            'detail' => 'Current: '.PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
            'critical' => true,
        ];

        // Required PHP extensions (critical)
        $extensions = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'zip', 'gd', 'curl', 'iconv', 'intl'];
        foreach ($extensions as $ext) {
            $results[] = [
                'name' => "PHP Extension: {$ext}",
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
                'passed' => extension_loaded($ext),
                'critical' => true,
            ];
        }

        // Optional PHP extensions (non-critical — fallbacks exist)
        $optionalExtensionFallbacks = [
            'imagick' => [
                'fallback' => 'Image processing will fall back to the GD extension instead.',
                'benefit' => 'If installed: higher-quality image resizing/cropping, broader format support (e.g. TIFF, PSD), and better performance on large images.',
            ],
            'gmp' => [
                'fallback' => 'Cryptographic math will fall back to a slower native PHP implementation instead.',
                'benefit' => 'If installed: faster, more efficient arbitrary-precision math for cryptographic operations.',
            ],
        ];
        foreach ($optionalExtensionFallbacks as $ext => $info) {
            $results[] = [
                'name' => "PHP Extension: {$ext} (optional)",
                'detail' => extension_loaded($ext) ? 'Loaded' : "Not loaded — {$info['fallback']} {$info['benefit']}",
                'passed' => extension_loaded($ext),
                'critical' => false,
            ];
        }

        // ZipArchive
        $results[] = [
            'name' => 'ZipArchive Class',
            'detail' => class_exists('ZipArchive') ? 'Available' : 'Not available',
            'passed' => class_exists('ZipArchive'),
            'critical' => true,
        ];

        // mod_rewrite check
        $modRewrite = $this->checkModRewrite();
        $results[] = [
            'name' => 'Apache mod_rewrite',
            'detail' => $modRewrite['passed']
                ? $modRewrite['detail']
                : $modRewrite['detail'].' If enabled: clean, SEO-friendly URLs without index.php in the path.',
            'passed' => $modRewrite['passed'],
            'critical' => false, // warning only
        ];

        // Writable directory
        $results[] = [
            'name' => 'Document Root Writable',
            'detail' => is_writable(__DIR__) ? 'Writable' : 'Not writable',
            'passed' => is_writable(__DIR__),
            'critical' => true,
        ];

        // Restricted core functions (must not be in disable_functions — used by Laravel Scheduler & Symfony Process)
        $requiredCoreFunctions = ['exec', 'shell_exec', 'proc_open', 'proc_close'];
        $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
        $blockedFunctions = array_filter($requiredCoreFunctions, fn ($f) => in_array($f, $disabledFunctions));
        $results[] = [
            'name' => 'Core Process Functions',
            'detail' => empty($blockedFunctions)
                ? 'exec, shell_exec, proc_open, proc_close are available'
                : 'Disabled via disable_functions: '.implode(', ', $blockedFunctions).' If enabled: scheduled tasks (Laravel Scheduler) and background processes (Symfony Process) can run correctly.',
            'passed' => empty($blockedFunctions),
            'critical' => false,
        ];

        // Zip file exists
        $results[] = [
            'name' => 'Application Package',
            'detail' => file_exists(__DIR__.'/'.ZIP_FILENAME) ? 'Found '.ZIP_FILENAME : ZIP_FILENAME.' Not found',
            'passed' => file_exists(__DIR__.'/'.ZIP_FILENAME),
            'critical' => true,
        ];

        return $results;
    }

    private function checkModRewrite(): array
    {
        // Method 1: apache_get_modules (works when PHP is Apache module)
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            if (in_array('mod_rewrite', $modules)) {
                return ['passed' => true, 'detail' => 'Enabled (detected via Apache modules)'];
            }

            return ['passed' => false, 'detail' => 'Not detected in Apache modules'];
        }

        // Method 2: For CGI/FPM - create a temporary .htaccess test
        $testDir = __DIR__.'/_rewrite_test_'.uniqid();
        $testHtaccess = $testDir.'/.htaccess';
        $testTarget = $testDir.'/target.php';

        try {
            if (! mkdir($testDir, 0755, true)) {
                return ['passed' => true, 'detail' => 'Could not verify (unable to create test directory). Ensure mod_rewrite is enabled.'];
            }

            file_put_contents($testTarget, '<?php echo "OK";');
            file_put_contents($testHtaccess, "RewriteEngine On\nRewriteRule ^test$ target.php [L]");

            // Build the test URL
            $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $scriptDir = $scriptDir === '/' ? '' : $scriptDir;
            $testUrl = "{$protocol}://{$host}{$scriptDir}/".basename($testDir).'/test';

            // Attempt a self-request
            $result = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($testUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $result = ($httpCode === 200 && trim($response) === 'OK');
            } elseif (ini_get('allow_url_fopen')) {
                $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => false]]);
                $response = @file_get_contents($testUrl, false, $ctx);
                $result = ($response !== false && trim($response) === 'OK');
            }

            // Cleanup
            @unlink($testTarget);
            @unlink($testHtaccess);
            @rmdir($testDir);

            if ($result) {
                return ['passed' => true, 'detail' => 'Enabled (verified via rewrite test)'];
            }

            return ['passed' => true, 'detail' => 'Could not verify automatically. Please ensure mod_rewrite is enabled in your Apache configuration.'];
        } catch (Exception $e) {
            // Cleanup on error
            @unlink($testTarget ?? '');
            @unlink($testHtaccess ?? '');
            @rmdir($testDir ?? '');

            return ['passed' => true, 'detail' => 'Could not verify (error during test). Ensure mod_rewrite is enabled.'];
        }
    }

    // ========================================================================
    // Installation Tasks (AJAX)
    // ========================================================================

    private function handleInstallTask(string $task): void
    {
        header('Content-Type: application/json');

        // Check if task was already completed (with verification for extract task)
        $completedTasks = $_SESSION['installer']['completed_tasks'] ?? [];
        if (in_array($task, $completedTasks)) {
            // Re-verify extract task — previous run may have silently failed
            if ($task === 'extract' && ! is_dir(__DIR__.'/'.APP_FOLDER.'/public')) {
                // Remove extract from completed tasks so it runs again
                $_SESSION['installer']['completed_tasks'] = array_values(
                    array_diff($completedTasks, ['extract'])
                );
            } else {
                echo json_encode(['success' => true, 'message' => 'Already completed.']);
                exit;
            }
        }

        try {
            switch ($task) {
                case 'extract':
                    $this->taskExtract();
                    break;
                case 'htaccess':
                    $this->taskHtaccess();
                    break;
                case 'env':
                    $this->taskGenerateEnv();
                    break;
                case 'migrate':
                    $this->taskMigrate();
                    break;
                case 'seed':
                    $this->taskSeed();
                    break;
                case 'seed_batch':
                    $this->taskSeedBatch();
                    break;
                case 'storage_link':
                    $this->taskStorageLink();
                    break;
                case 'config_clear':
                    $this->taskConfigClear();
                    break;
                case 'package_discover':
                    $this->taskPackageDiscover();
                    break;
                case 'config_cache':
                case 'event_cache':
                case 'route_cache':
                case 'view_cache':
                case 'icons_cache':
                case 'filament_optimize':
                    $this->taskOptimizeStep($task);
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Unknown task.']);
                    exit;
            }

            $_SESSION['installer']['completed_tasks'][] = $task;
            echo json_encode(['success' => true, 'message' => 'Completed.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * Extract the application zip in batches to avoid gateway timeouts on
     * shared hosting. Each call extracts a chunk of files and returns
     * progress. The caller must keep invoking this until 'extract_done'
     * is true in the JSON response.
     *
     * On the first call, the zip is copied to a temporary working location
     * so the original is never at risk of corruption from a timed-out request.
     */
    private function taskExtract(): void
    {
        $batchSize = 500;
        $zipPath = __DIR__.'/'.ZIP_FILENAME;
        $workingZip = __DIR__.'/.install-working.zip';

        if (! file_exists($zipPath) && ! file_exists($workingZip)) {
            throw new RuntimeException('Application package not found: '.ZIP_FILENAME);
        }

        // On the first call, copy the zip to a working location so the
        // original cannot be corrupted by a timed-out or interrupted request.
        if (! file_exists($workingZip)) {
            if (! copy($zipPath, $workingZip)) {
                throw new RuntimeException('Failed to create working copy of '.ZIP_FILENAME.'. Check directory permissions and available disk space.');
            }
        }

        $zip = new ZipArchive;
        $result = $zip->open($workingZip);

        if ($result !== true) {
            $fileSize = filesize($workingZip);
            $errors = [
                ZipArchive::ER_EXISTS => 'File already exists.',
                ZipArchive::ER_INCONS => 'Zip archive inconsistent.',
                ZipArchive::ER_INVAL => 'Invalid argument.',
                ZipArchive::ER_MEMORY => 'Memory allocation failure.',
                ZipArchive::ER_NOENT => 'No such file.',
                ZipArchive::ER_NOZIP => 'Not a zip archive.',
                ZipArchive::ER_OPEN => 'Cannot open file.',
                ZipArchive::ER_READ => 'Read error.',
                ZipArchive::ER_SEEK => 'Seek error.',
            ];
            $errorMsg = $errors[$result] ?? "Unknown error (code: {$result})";

            // Remove the corrupted working copy so a retry will re-copy from the original
            @unlink($workingZip);

            throw new RuntimeException(
                'Failed to open zip: '.$errorMsg
                .' (file size: '.number_format($fileSize).' bytes)'
                .' Please try again.'
            );
        }

        $totalFiles = $zip->numFiles;
        $offset = $_SESSION['installer']['extract_offset'] ?? 0;

        // Extract a batch of files starting from the current offset
        $end = min($offset + $batchSize, $totalFiles);

        // Collect file names for this batch, pre-creating directories as needed.
        // Passing an array to extractTo() is significantly faster than extracting
        // files one-by-one because ZipArchive handles the batch in a single C-level
        // pass rather than re-seeking through the archive for each file.
        $filesToExtract = [];
        for ($i = $offset; $i < $end; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directory entries — they are created automatically when
            // extracting the files they contain.
            if ($name === false || substr($name, -1) === '/') {
                continue;
            }

            // Ensure the parent directory exists
            $destDir = dirname(__DIR__.'/'.$name);
            if (! is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $filesToExtract[] = $name;
        }

        // Extract the entire batch in a single call
        if (! empty($filesToExtract) && ! $zip->extractTo(__DIR__, $filesToExtract)) {
            $zip->close();
            throw new RuntimeException('Failed to extract batch at offset '.$offset.'. Check directory permissions and available disk space.');
        }

        $zip->close();

        $_SESSION['installer']['extract_offset'] = $end;

        // Not finished yet — signal the frontend to call again
        if ($end < $totalFiles) {
            $percent = round(($end / $totalFiles) * 100);
            echo json_encode([
                'success' => true,
                'extract_done' => false,
                'message' => "Extracted {$end}/{$totalFiles} files ({$percent}%)",
                'percent' => $percent,
            ]);
            exit;
        }

        // Extraction complete — clean up working copy and verify
        unset($_SESSION['installer']['extract_offset']);
        @unlink($workingZip);

        if (! is_dir(__DIR__.'/'.APP_FOLDER)) {
            // Gather diagnostic info to help troubleshoot
            $contents = array_slice(scandir(__DIR__), 0, 20);
            throw new RuntimeException(
                'Extraction completed but the application folder ('.APP_FOLDER.') was not found in '.__DIR__
                .'. Directory contents: '.implode(', ', $contents)
            );
        }

        // Verify critical subdirectories exist
        $requiredDirs = ['public', 'vendor', 'bootstrap', 'storage'];
        $missingDirs = [];
        foreach ($requiredDirs as $dir) {
            if (! is_dir(__DIR__.'/'.APP_FOLDER.'/'.$dir)) {
                $missingDirs[] = $dir;
            }
        }

        if (! empty($missingDirs)) {
            $appContents = implode(', ', array_slice(scandir(__DIR__.'/'.APP_FOLDER), 2, 10));
            throw new RuntimeException(
                'Extraction completed but required directories are missing: '.implode(', ', $missingDirs)
                .'. Found: '.$appContents.'. Check disk space and directory permissions.'
            );
        }
    }

    private function taskHtaccess(): void
    {
        $appFolder = APP_FOLDER;
        $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Allow the installer to run
    RewriteRule ^install\.php$ - [L]
    RewriteRule ^_cleanup\.php$ - [L]

    # Rewrite everything else to the application public directory
    RewriteCond %{REQUEST_URI} !^/{$appFolder}/public/
    RewriteRule ^(.*)$ {$appFolder}/public/\$1 [L]
</IfModule>
HTACCESS;

        $htaccessPath = __DIR__.'/.htaccess';

        if (file_put_contents($htaccessPath, $htaccess) === false) {
            throw new RuntimeException('Failed to write .htaccess file. Check directory permissions.');
        }
    }

    private function taskGenerateEnv(): void
    {
        $data = $_SESSION['installer'];
        $db = $data['db'];
        $settings = $data['settings'];
        $mail = $data['mail'];
        $admin = $data['admin'];

        // Ensure required writable directories exist with correct permissions.
        $appDir = __DIR__.'/'.APP_FOLDER;
        $writableDirs = [
            $appDir.'/bootstrap/cache',
            $appDir.'/storage',
            $appDir.'/storage/app',
            $appDir.'/storage/app/public',
            $appDir.'/storage/framework',
            $appDir.'/storage/framework/cache',
            $appDir.'/storage/framework/cache/data',
            $appDir.'/storage/framework/sessions',
            $appDir.'/storage/framework/views',
            $appDir.'/storage/logs',
        ];
        foreach ($writableDirs as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            chmod($dir, 0755);
        }

        // Clear cached bootstrap files — they may reference dev-only
        // packages (e.g. debugbar) that are not shipped in the zip.
        $cacheDir = $appDir.'/bootstrap/cache';
        foreach (['packages.php', 'services.php', 'config.php', 'routes-v7.php', 'blade-icons.php', 'events.php'] as $cacheFile) {
            @unlink($cacheDir.'/'.$cacheFile);
        }

        // Generate a fresh APP_KEY
        $appKey = 'base64:'.base64_encode(random_bytes(32));

        $env = <<<ENV
APP_NAME="{$this->escapeEnvValue($settings['app_name'])}"
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL={$settings['app_url']}

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST={$db['host']}
DB_PORT={$db['port']}
DB_DATABASE={$db['name']}
DB_USERNAME={$db['user']}
DB_PASSWORD="{$this->escapeEnvValue($db['pass'])}"

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

MAIL_MAILER={$mail['mail_mailer']}
MAIL_SCHEME=null
MAIL_HOST={$mail['mail_host']}
MAIL_PORT={$mail['mail_port']}
MAIL_USERNAME={$mail['mail_username']}
MAIL_PASSWORD="{$this->escapeEnvValue($mail['mail_password'])}"
MAIL_FROM_ADDRESS="{$mail['mail_from_address']}"
MAIL_FROM_NAME="{$this->escapeEnvValue($mail['mail_from_name'])}"

FIRST_USER_EMAIL={$admin['email']}
FIRST_USER_FIRST_NAME="{$this->escapeEnvValue($admin['first_name'])}"
FIRST_USER_LAST_NAME="{$this->escapeEnvValue($admin['last_name'])}"
FIRST_USER_PASSWORD="{$this->escapeEnvValue($admin['password'])}"

HEALTH_MAIL_TO_ADDRESS={$admin['email']}
ENV;

        $envPath = __DIR__.'/'.APP_FOLDER.'/.env';
        if (file_put_contents($envPath, $env) === false) {
            throw new RuntimeException('Failed to write .env file. Check directory permissions.');
        }
    }

    private function escapeEnvValue(string $value): string
    {
        return str_replace('"', '\\"', $value);
    }

    private function bootstrapLaravel(): object
    {
        $autoloadPath = __DIR__.'/'.APP_FOLDER.'/vendor/autoload.php';
        $bootstrapPath = __DIR__.'/'.APP_FOLDER.'/bootstrap/app.php';

        if (! file_exists($autoloadPath)) {
            throw new RuntimeException('Vendor autoload not found. The application may not have been extracted correctly.');
        }

        // Force file-based drivers during installation to avoid database table
        // dependencies before migrations have run (sessions, cache, queue tables
        // don't exist yet).
        putenv('SESSION_DRIVER=file');
        putenv('CACHE_STORE=file');
        putenv('QUEUE_CONNECTION=sync');
        $_ENV['SESSION_DRIVER'] = 'file';
        $_ENV['CACHE_STORE'] = 'file';
        $_ENV['QUEUE_CONNECTION'] = 'sync';
        $_SERVER['SESSION_DRIVER'] = 'file';
        $_SERVER['CACHE_STORE'] = 'file';
        $_SERVER['QUEUE_CONNECTION'] = 'sync';

        require $autoloadPath;
        $app = require $bootstrapPath;

        return $app->make(Illuminate\Contracts\Console\Kernel::class);
    }

    /**
     * Run an Artisan command safely, capturing output and preventing
     * Laravel's exception handler from rendering HTML error pages.
     */
    private function runArtisanCommand(string $command, array $params = []): string
    {
        $kernel = $this->bootstrapLaravel();
        $output = new Symfony\Component\Console\Output\BufferedOutput;

        // Capture any stray output (e.g. from Laravel's exception handler
        // rendering HTML) so it doesn't corrupt our JSON response.
        ob_start();

        try {
            $exitCode = $kernel->call($command, $params, $output);
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException("{$command} failed: ".$e->getMessage());
        }

        $strayOutput = ob_get_clean();
        $artisanOutput = trim($output->fetch());

        if ($exitCode !== 0) {
            $message = $artisanOutput ?: $strayOutput ?: 'Unknown error';
            throw new RuntimeException("{$command} failed (exit code {$exitCode}): ".substr($message, 0, 500));
        }

        return $artisanOutput;
    }

    private function taskMigrate(): void
    {
        $this->runArtisanCommand('migrate:fresh', ['--force' => true]);
    }

    /**
     * Essential seeder classes that are always run. These set up core
     * configuration, species, menus, pages, and other foundational data.
     *
     * @var list<string>
     */
    private const ESSENTIAL_SEED_CLASSES = [
        'Database\\Seeders\\AdminUserSeeder',
        'Database\\Seeders\\SpeciesSeeder',
        'Database\\Seeders\\MembershipPlanSeeder',
        'Database\\Seeders\\FormQuestionSeeder',
        'Database\\Seeders\\SettingSeeder',
        'Database\\Seeders\\TagSeeder',
        'Database\\Seeders\\MenuSeeder',
        'Database\\Seeders\\PageSeeder',
    ];

    /**
     * Additional seeder classes that populate the site with demonstration
     * data (sample pets, applications, blog posts, etc.).
     *
     * @var list<string>
     */
    private const SAMPLE_SEED_CLASSES = [
        'Database\\Seeders\\PetSeeder',
        'Database\\Seeders\\AdoptionApplicationSeeder',
        'Database\\Seeders\\FosteringApplicationSeeder',
        'Database\\Seeders\\AssistanceApplicationSeeder',
        'Database\\Seeders\\ApplicationStatusHistorySeeder',
        'Database\\Seeders\\InterviewSeeder',
        'Database\\Seeders\\BlogPostSeeder',
        'Database\\Seeders\\MembershipSeeder',
        'Database\\Seeders\\DrawSeeder',
        'Database\\Seeders\\DocumentSeeder',
    ];

    /**
     * Get the list of seeder classes to run based on the user's preference.
     *
     * @return list<string>
     */
    private function getSeedClasses(): array
    {
        $sampleData = $_SESSION['installer']['settings']['sample_data'] ?? 'essential';

        if ($sampleData === 'full') {
            return array_merge(self::ESSENTIAL_SEED_CLASSES, self::SAMPLE_SEED_CLASSES);
        }

        return self::ESSENTIAL_SEED_CLASSES;
    }

    /**
     * Run seeders one class at a time across multiple HTTP requests,
     * similar to how taskExtract() handles batched extraction.
     * The first call comes in as 'seed', subsequent calls as 'seed_batch'.
     */
    private function taskSeed(): void
    {
        $_SESSION['installer']['seed_index'] = 0;
        $this->runSeedBatch();
    }

    private function taskSeedBatch(): void
    {
        $this->runSeedBatch();
    }

    private function runSeedBatch(): void
    {
        $seedClasses = $this->getSeedClasses();
        $seedIndex = $_SESSION['installer']['seed_index'] ?? 0;
        $total = count($seedClasses);

        if ($seedIndex >= $total) {
            echo json_encode([
                'success' => true,
                'seed_done' => true,
                'message' => 'Completed.',
            ]);
            exit;
        }

        $class = $seedClasses[$seedIndex];
        $shortName = substr($class, strrpos($class, '\\') + 1);

        $this->runArtisanCommand('db:seed', [
            '--force' => true,
            '--class' => $class,
            '--no-interaction' => true,
        ]);

        $_SESSION['installer']['seed_index'] = $seedIndex + 1;
        $done = ($seedIndex + 1) >= $total;

        echo json_encode([
            'success' => true,
            'seed_done' => $done,
            'message' => "Seeded {$shortName} (".($seedIndex + 1)."/{$total})",
        ]);
        exit;
    }

    private function taskStorageLink(): void
    {
        $this->runArtisanCommand('storage:link');
    }

    /**
     * Generate the standalone optimizer script and a security token.
     *
     * Commands like config:clear, package:discover, and optimize must
     * run in a completely separate PHP process so they don't inherit the
     * polluted environment from earlier in-process Laravel boots (which
     * override SESSION_DRIVER, CACHE_STORE, etc.). The browser's JS
     * fetches this file directly for those tasks.
     */
    private function ensureOptimizerEndpoint(): void
    {
        if (! isset($_SESSION['installer']['optimize_token'])) {
            $_SESSION['installer']['optimize_token'] = bin2hex(random_bytes(32));
        }

        $optimizerPath = __DIR__.'/'.APP_FOLDER.'/public/install-optimize.php';
        $token = $_SESSION['installer']['optimize_token'];

        $script = <<<OPTIMIZER_PHP
<?php

/**
 * Standalone optimizer endpoint — runs Artisan commands in a clean
 * PHP process through the application's own public directory, ensuring
 * the environment is identical to normal web requests. Generated by
 * install.php and automatically cleaned up after installation.
 */
header('Content-Type: application/json');

\$expectedToken = '{$token}';

if (! isset(\$_GET['token']) || ! hash_equals(\$expectedToken, \$_GET['token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

\$command = \$_GET['command'] ?? '';
\$allowed = ['config:clear', 'config:cache', 'event:cache', 'route:cache', 'view:cache', 'icons:cache', 'filament:optimize', 'package:discover'];

if (! in_array(\$command, \$allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid command.']);
    exit;
}

// Bootstrap Laravel from its own directory.  We must trick the
// application into thinking it is running in the console so that
// service providers register their Artisan commands (Filament,
// Blade Icons, etc. gate command registration behind
// runningInConsole()).
putenv('APP_RUNNING_IN_CONSOLE=true');
\$_ENV['APP_RUNNING_IN_CONSOLE'] = 'true';
\$_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';

require __DIR__ . '/../vendor/autoload.php';
\$app = require __DIR__ . '/../bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$output = new Symfony\Component\Console\Output\BufferedOutput;

ob_start();

try {
    \$exitCode = \$kernel->call(\$command, ['--no-interaction' => true], \$output);
} catch (Throwable \$e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => \$command . ' failed: ' . \$e->getMessage()]);
    exit;
}

ob_end_clean();

if (\$exitCode !== 0) {
    \$message = trim(\$output->fetch()) ?: 'Unknown error';
    echo json_encode(['success' => false, 'message' => \$command . ' failed (exit code ' . \$exitCode . '): ' . substr(\$message, 0, 500)]);
    exit;
}

// After config:cache, verify the cache file exists and contains
// correct values.  Running config:cache from a web request can
// silently produce a broken cache (wrong drivers, missing keys)
// because Dotenv's immutable repository refuses to overwrite
// values already present in \$_SERVER/\$_ENV.
if (\$command === 'config:cache') {
    \$cachePath = \$app->getCachedConfigPath();

    if (! is_file(\$cachePath)) {
        echo json_encode(['success' => false, 'message' => 'config:cache reported success but cache file was not created. Check bootstrap/cache/ permissions.']);
        exit;
    }

    \$cached = require \$cachePath;
    \$problems = [];

    // Read expected values straight from the .env file to compare
    // against the cached config.  This detects when the web server
    // environment leaked values that override what .env specifies.
    \$envPath = __DIR__ . '/../.env';
    \$envValues = [];

    if (is_file(\$envPath)) {
        foreach (file(\$envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as \$line) {
            if (str_starts_with(\$line, '#')) {
                continue;
            }
            if (str_contains(\$line, '=')) {
                [\$k, \$v] = explode('=', \$line, 2);
                \$envValues[trim(\$k)] = trim(\$v, " \t\"'");
            }
        }
    }

    \$checks = [
        'session.driver'  => \$envValues['SESSION_DRIVER'] ?? null,
        'cache.default'   => \$envValues['CACHE_STORE'] ?? null,
        'queue.default'   => \$envValues['QUEUE_CONNECTION'] ?? null,
        'app.url'         => \$envValues['APP_URL'] ?? null,
    ];

    foreach (\$checks as \$key => \$expected) {
        if (\$expected === null) {
            continue;
        }

        \$parts = explode('.', \$key);
        \$value = \$cached;

        foreach (\$parts as \$part) {
            \$value = \$value[\$part] ?? null;
        }

        if (\$value !== \$expected) {
            \$problems[] = \$key . ' is "' . (\$value ?? 'null') . '" (expected "' . \$expected . '")';
        }
    }

    if (! empty(\$problems)) {
        // Delete the broken cache so the app falls back to .env
        @unlink(\$cachePath);
        echo json_encode(['success' => false, 'message' => 'Config cache had incorrect values — ' . implode('; ', \$problems) . '. Cache removed; the web server environment may be overriding .env values.']);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Completed.']);
OPTIMIZER_PHP;

        if (file_put_contents($optimizerPath, $script) === false) {
            throw new RuntimeException('Failed to write install-optimize.php. Check directory permissions.');
        }
    }

    /**
     * Prepare the optimizer endpoint for config:clear (the actual
     * command runs in a separate HTTP request via the browser JS).
     */
    private function taskConfigClear(): void
    {
        $this->ensureOptimizerEndpoint();
    }

    /**
     * Prepare the optimizer endpoint for package:discover.
     */
    private function taskPackageDiscover(): void
    {
        $this->ensureOptimizerEndpoint();
    }

    /**
     * Prepare the optimizer endpoint for an individual optimization
     * sub-command. Each runs in its own HTTP request to avoid gateway
     * timeouts on shared hosting.
     */
    private function taskOptimizeStep(string $task): void
    {
        $this->ensureOptimizerEndpoint();

        // Mark installation as complete after the final optimization step.
        if ($task === 'filament_optimize') {
            $_SESSION['installer']['install_complete'] = true;
        }
    }

    /**
     * Remove the standalone optimizer endpoint after installation.
     */
    private function cleanupOptimizerEndpoint(): void
    {
        $optimizerPath = __DIR__.'/'.APP_FOLDER.'/public/install-optimize.php';

        if (file_exists($optimizerPath)) {
            @unlink($optimizerPath);
        }
    }

    // ========================================================================
    // Step Renderers
    // ========================================================================

    private function renderStep(int $step): void
    {
        switch ($step) {
            case 1:
                $this->renderEula();
                break;
            case 2:
                $this->renderRequirements();
                break;
            case 3:
                $this->renderDatabase();
                break;
            case 4:
                $this->renderSettings();
                break;
            case 5:
                $this->renderEmail();
                break;
            case 6:
                $this->renderAdmin();
                break;
            case 7:
                $this->renderInstall();
                break;
            case 8:
                $this->renderCron();
                break;
            case 9:
                $this->renderComplete();
                break;
        }
    }

    private function renderEula(): void
    {
        $errors = $this->renderErrors();
        $eula = htmlspecialchars(EULA_TEXT);

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=1">
            <!-- EULA Box -->
            <div class="bg-gradient-to-br from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6 shadow-sm">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⚖️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white text-base">End User License Agreement</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Please read carefully before proceeding</p>
                        </div>
                    </div>
                </div>
                <div class="p-6 max-h-80 overflow-y-auto scrollbar-hide">
                    <div class="prose prose-sm max-w-none text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">{$eula}</div>
                </div>
            </div>

            <!-- Checkbox -->
            <div class="bg-gradient-to-r from-sky-50 to-cyan-50 dark:from-sky-900/20 dark:to-cyan-900/20 rounded-xl p-4 mb-6 border border-sky-200 dark:border-sky-800">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="accept_eula" value="1" id="accept-eula" class="w-5 h-5 mt-0.5 rounded border-slate-300 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                    <div>
                        <span class="font-medium text-slate-900 dark:text-white">I have read and agree to the End User License Agreement</span>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">By checking this box, you confirm that you understand and accept all terms</p>
                    </div>
                </label>
            </div>

            <!-- Actions -->
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" id="accept-btn" disabled>I Accept →</button>
            </div>
        </form>
        <script>
            document.getElementById('accept-eula').addEventListener('change', function() {
                document.getElementById('accept-btn').disabled = !this.checked;
            });
        </script>
HTML;

        $this->renderLayout('License Agreement', $content, 1);
    }

    private function renderRequirements(): void
    {
        $results = $this->checkRequirements();
        $allCriticalPassed = true;
        foreach ($results as $r) {
            if (! $r['passed'] && $r['critical']) {
                $allCriticalPassed = false;
            }
        }

        // Only surface individual detail cards for checks that need attention.
        // Passing checks are collapsed into a single summary banner to save space.
        $passed = array_filter($results, fn ($r) => $r['passed']);
        $notPassed = array_filter($results, fn ($r) => ! $r['passed']);

        $items = '';
        if (! empty($passed)) {
            $items .= "<div class=\"flex items-center gap-4 p-4 rounded-xl border-2 bg-white dark:bg-slate-800 border-emerald-300 dark:border-emerald-700 shadow-sm\">
                <div class=\"flex-shrink-0 w-10 h-10 rounded-xl text-emerald-500 bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center\">
                    <span class=\"text-lg font-bold\">✓</span>
                </div>
                <div class=\"flex-1\">
                    <h4 class=\"font-semibold text-slate-900 dark:text-white text-sm\">".count($passed)." requirement".(count($passed) === 1 ? '' : 's')." met</h4>
                    <p class=\"text-xs text-slate-600 dark:text-slate-400 mt-0.5\">".implode(', ', array_map(fn ($r) => $r['name'], $passed))."</p>
                </div>
                <span class=\"px-3 py-1 rounded-full text-emerald-500 bg-emerald-100 dark:bg-emerald-900/30 text-xs font-semibold\">Passed</span>
            </div>";
        }
        foreach ($notPassed as $r) {
            $icon = $r['critical'] ? '✕' : '⚠';
            $statusClass = $r['critical'] ? 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-300 dark:border-yellow-700';
            $iconClass = $r['critical'] ? 'text-red-500 bg-red-100 dark:bg-red-900/30' : 'text-yellow-500 bg-yellow-100 dark:bg-yellow-900/30';
            $items .= "<div class=\"flex items-center gap-4 p-4 rounded-xl border-2 {$statusClass} shadow-sm hover:shadow-md transition-shadow\">
                <div class=\"flex-shrink-0 w-10 h-10 rounded-xl {$iconClass} flex items-center justify-center\">
                    <span class=\"text-lg font-bold\">{$icon}</span>
                </div>
                <div class=\"flex-1\">
                    <h4 class=\"font-semibold text-slate-900 dark:text-white text-sm\">{$r['name']}</h4>
                    <p class=\"text-xs text-slate-600 dark:text-slate-400 mt-0.5\">{$r['detail']}</p>
                </div>
                <span class=\"px-3 py-1 rounded-full {$iconClass} text-xs font-semibold\">" . ($r['critical'] ? 'Critical' : 'Warning') . "</span>
            </div>";
        }

        $disabled = $allCriticalPassed ? '' : 'disabled';
        $retestButton = $allCriticalPassed ? '' : '<button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all" onclick="window.location.href=\'install.php?step=2\'">Re-Test</button>';
        $warning = $allCriticalPassed ? '' : '<div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <span class="text-red-500 text-lg">⚠️</span>
            </div>
            <div>
                <h3 class="font-semibold text-red-900 dark:text-red-400">Requirements Not Met</h3>
                <p class="text-red-700 dark:text-red-300 text-sm">Some critical requirements are not met. Please resolve them before continuing.</p>
            </div>
        </div>';

        $content = <<<HTML
        {$warning}
        <form method="POST" action="install.php?step=2">
        <div class="grid gap-3 mb-6">{$items}</div>
        <div class="flex justify-between items-center gap-3">
            <a href="install.php?step=1" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
            <div class="flex gap-3">
                {$retestButton}
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" {$disabled}>Continue →</button>
            </div>
        </div>
        </form>
HTML;

        $this->renderLayout('Server Requirements', $content, 2);
    }

    private function renderDatabase(): void
    {
        $errors = $this->renderErrors();
        $db = $_SESSION['installer']['db'] ?? [];

        $host = htmlspecialchars($db['host'] ?? 'localhost');
        $port = htmlspecialchars($db['port'] ?? '3306');
        $name = htmlspecialchars($db['name'] ?? '');
        $user = htmlspecialchars($db['user'] ?? '');
        $pass = htmlspecialchars($db['pass'] ?? '');

        $content = <<<HTML
        {$errors}
        <div id="db-test-result" class="hidden"></div>
        <form method="POST" action="install.php?step=3" id="db-form">
            <!-- Database Configuration Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">🗄️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">MySQL Database Connection</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Provide your database credentials</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Host <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_host" id="db_host" value="{$host}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🌐</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Port</label>
                            <div class="relative">
                                <input type="text" name="db_port" id="db_port" value="{$port}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔢</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Name <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_name" id="db_name" value="{$name}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">📊</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Username <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_user" id="db_user" value="{$user}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Password</label>
                            <div class="relative">
                                <input type="password" name="db_pass" id="db_pass" value="{$pass}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=2" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <div class="flex gap-3">
                    <button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all flex items-center gap-2" id="test-db-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('test-db-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('db-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin">⏳</span> Testing...';
                resultDiv.classList.add('hidden');

                var formData = new FormData(document.getElementById('db-form'));

                fetch('install.php?step=3&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = data.success 
                        ? 'bg-gradient-to-r from-emerald-50 to-green-50 dark:from-emerald-900/20 dark:to-green-900/20 border-2 border-emerald-300 dark:border-emerald-700 rounded-xl p-4 mb-6 flex items-start gap-3'
                        : 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg ' + (data.success ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-red-100 dark:bg-red-900/30') + ' flex items-center justify-center">' +
                        '<span class="' + (data.success ? 'text-emerald-500' : 'text-red-500') + ' text-lg">' + (data.success ? '✓' : '✕') + '</span></div>' +
                        '<div><p class="font-semibold ' + (data.success ? 'text-emerald-900 dark:text-emerald-400' : 'text-red-900 dark:text-red-400') + '">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="text-sm ' + (data.success ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300') + '">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center"><span class="text-red-500 text-lg">✕</span></div><div><p class="font-semibold text-red-900 dark:text-red-400">Error</p><p class="text-sm text-red-700 dark:text-red-300">An error occurred while testing the connection.</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                });
            });
        </script>
HTML;

        $this->renderLayout('Database Configuration', $content, 3);
    }

    private function renderSettings(): void
    {
        $errors = $this->renderErrors();
        $s = $_SESSION['installer']['settings'] ?? [];

        $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $defaultUrl = $protocol.'://'.$host;

        $appName = htmlspecialchars($s['app_name'] ?? ucwords(str_replace(['-', '_'], ' ', APP_FOLDER)));
        $appUrl = htmlspecialchars($s['app_url'] ?? $defaultUrl);
        $timezone = $s['timezone'] ?? 'America/Toronto';
        $sampleData = $s['sample_data'] ?? 'essential';
        $essentialChecked = $sampleData === 'essential' ? ' checked' : '';
        $fullChecked = $sampleData === 'full' ? ' checked' : '';

        $timezones = DateTimeZone::listIdentifiers();
        $tzOptions = '';
        foreach ($timezones as $tz) {
            $selected = ($tz === $timezone) ? ' selected' : '';
            $tzOptions .= "<option value=\"{$tz}\"{$selected}>{$tz}</option>";
        }

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=4">
            <!-- Application Settings -->
            <div class="mb-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
                    Application
                </h3>
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Application Name <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" name="app_name" id="app_name" value="{$appName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🏷️</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Application URL <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="url" name="app_url" id="app_url" value="{$appUrl}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🌐</span>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Timezone <span class="text-red-500">*</span></label>
                        <select name="timezone" id="timezone" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white cursor-pointer">{$tzOptions}</select>
                    </div>
                </div>
            </div>

            <!-- Initial Data -->
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2 flex items-center gap-2">
                    <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
                    Initial Data
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-sm mb-4">Choose how much content to pre-populate your site with. You can always add your own data later.</p>
                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:border-sky-300 dark:hover:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all bg-white dark:bg-slate-800">
                        <input type="radio" name="sample_data" value="essential"{$essentialChecked} class="w-5 h-5 mt-0.5 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                        <div>
                            <span class="font-semibold text-slate-900 dark:text-white">Essentials only</span>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Sets up core configuration, menus, and pages — a clean slate ready for your own affiliates and content.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:border-sky-300 dark:hover:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all bg-white dark:bg-slate-800">
                        <input type="radio" name="sample_data" value="full"{$fullChecked} class="w-5 h-5 mt-0.5 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                        <div>
                            <span class="font-semibold text-slate-900 dark:text-white">Full demonstration data</span>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Includes sample affiliates, applications, blog posts, and more — ideal for exploring all features before going live.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-200 dark:border-slate-700">
                <a href="install.php?step=3" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
            </div>
        </form>
HTML;

        $this->renderLayout('Application Settings', $content, 4);
    }

    private function renderEmail(): void
    {
        $errors = $this->renderErrors();
        $m = $_SESSION['installer']['mail'] ?? [];

        $mailMailer = $m['mail_mailer'] ?? 'log';
        $mailHost = htmlspecialchars($m['mail_host'] ?? '');
        $mailPort = htmlspecialchars($m['mail_port'] ?? '587');
        $mailUsername = htmlspecialchars($m['mail_username'] ?? '');
        $mailPassword = htmlspecialchars($m['mail_password'] ?? '');
        $mailFromAddress = htmlspecialchars($m['mail_from_address'] ?? '');
        $mailFromName = htmlspecialchars($m['mail_from_name'] ?? '');
        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));

        $smtpSelected = $mailMailer === 'smtp' ? ' selected' : '';
        $sendmailSelected = $mailMailer === 'sendmail' ? ' selected' : '';
        $logSelected = $mailMailer === 'log' ? ' selected' : '';
        $smtpDisplay = $mailMailer === 'smtp' ? '' : 'hidden';
        $sendmailDisplay = $mailMailer === 'sendmail' ? '' : 'hidden';
        $fromDisplay = $mailMailer === 'log' ? 'hidden' : '';

        $content = <<<HTML
        {$errors}
        <div id="mail-test-result" class="hidden"></div>
        <form method="POST" action="install.php?step=5" id="mail-form">
            <!-- Mail Configuration -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">✉️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Email Configuration</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Set up how this application sends email notifications</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Mail Driver</label>
                        <select name="mail_mailer" id="mail_mailer" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white cursor-pointer">
                            <option value="smtp"{$smtpSelected}>SMTP</option>
                            <option value="sendmail"{$sendmailSelected}>Sendmail (PHP mail)</option>
                            <option value="log"{$logSelected}>Log (no emails sent)</option>
                        </select>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Select "Log" if you want to configure email later.</p>
                    </div>

                    <!-- SMTP Fields -->
                    <div id="smtp-fields" class="{$smtpDisplay}">
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Host</label>
                                <input type="text" name="mail_host" id="mail_host" value="{$mailHost}" placeholder="smtp.example.com" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Port</label>
                                <input type="text" name="mail_port" id="mail_port" value="{$mailPort}" placeholder="587" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Username</label>
                                <input type="text" name="mail_username" id="mail_username" value="{$mailUsername}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Password</label>
                                <input type="password" name="mail_password" id="mail_password" value="{$mailPassword}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                        </div>
                    </div>

                    <!-- Sendmail Info -->
                    <div id="sendmail-fields" class="{$sendmailDisplay}">
                        <div class="bg-sky-50 dark:bg-sky-900/20 rounded-xl p-4 border border-sky-200 dark:border-sky-800">
                            <p class="text-sm text-sky-800 dark:text-sky-300">Uses your server's built-in sendmail/PHP mail function. No additional server configuration needed.</p>
                        </div>
                    </div>

                    <!-- From Fields -->
                    <div id="from-fields" class="{$fromDisplay} mt-6 grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">From Address</label>
                            <input type="email" name="mail_from_address" id="mail_from_address" value="{$mailFromAddress}" placeholder="noreply@yourdomain.com" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">From Name</label>
                            <input type="text" name="mail_from_name" id="mail_from_name" value="{$mailFromName}" placeholder="{$appName}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=4" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <div class="flex gap-3">
                    <button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all flex items-center gap-2" id="test-mail-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('mail_mailer').addEventListener('change', function() {
                document.getElementById('smtp-fields').classList.toggle('hidden', this.value !== 'smtp');
                document.getElementById('sendmail-fields').classList.toggle('hidden', this.value !== 'sendmail');
                document.getElementById('from-fields').classList.toggle('hidden', this.value === 'log');
                document.getElementById('mail-test-result').classList.add('hidden');
            });

            document.getElementById('test-mail-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('mail-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin">⏳</span> Testing...';
                resultDiv.classList.add('hidden');

                var formData = new FormData(document.getElementById('mail-form'));

                fetch('install.php?step=5&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = data.success 
                        ? 'bg-gradient-to-r from-emerald-50 to-green-50 dark:from-emerald-900/20 dark:to-green-900/20 border-2 border-emerald-300 dark:border-emerald-700 rounded-xl p-4 mb-6 flex items-start gap-3'
                        : 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg ' + (data.success ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-red-100 dark:bg-red-900/30') + ' flex items-center justify-center">' +
                        '<span class="' + (data.success ? 'text-emerald-500' : 'text-red-500') + ' text-lg">' + (data.success ? '✓' : '✕') + '</span></div>' +
                        '<div><p class="font-semibold ' + (data.success ? 'text-emerald-900 dark:text-emerald-400' : 'text-red-900 dark:text-red-400') + '">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="text-sm ' + (data.success ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300') + '">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center"><span class="text-red-500 text-lg">✕</span></div><div><p class="font-semibold text-red-900 dark:text-red-400">Error</p><p class="text-sm text-red-700 dark:text-red-300">An error occurred while testing the mail connection.</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                });
            });
        </script>
HTML;

        $this->renderLayout('Email Configuration', $content, 5);
    }

    private function renderAdmin(): void
    {
        $errors = $this->renderErrors();
        $admin = $_SESSION['installer']['admin'] ?? [];

        $firstName = htmlspecialchars($admin['first_name'] ?? '');
        $lastName = htmlspecialchars($admin['last_name'] ?? '');
        $email = htmlspecialchars($admin['email'] ?? '');

        $content = <<<HTML
        {$errors}
        <p class="text-slate-600 dark:text-slate-400 mb-6">Create your administrator account. You will use these credentials to log into the admin panel.</p>
        <form method="POST" action="install.php?step=6">
            <!-- Admin Account Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">👤</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Administrator Account</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Set up your admin credentials</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-5">
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">First Name <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="text" name="admin_first_name" id="admin_first_name" value="{$firstName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Last Name <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="text" name="admin_last_name" id="admin_last_name" value="{$lastName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Email Address <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="email" name="admin_email" id="admin_email" value="{$email}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">📧</span>
                            </div>
                        </div>
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="admin_password" id="admin_password" minlength="8" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                                </div>
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Minimum 8 characters</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Confirm Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="admin_password_confirm" id="admin_password_confirm" minlength="8" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=5" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
            </div>
        </form>
HTML;

        $this->renderLayout('Admin Account', $content, 6);
    }

    private function renderInstall(): void
    {
        $tasks = [
            'extract' => 'Extracting application files',
            'htaccess' => 'Configuring web server',
            'env' => 'Generating environment configuration',
            'migrate' => 'Running database migrations',
            'seed' => 'Seeding database with initial data',
            'storage_link' => 'Creating storage symlink',
            'config_clear' => 'Clearing configuration cache',
            'package_discover' => 'Discovering packages',
            'config_cache' => 'Caching configuration',
            'event_cache' => 'Caching events',
            'route_cache' => 'Caching routes',
            'view_cache' => 'Caching views',
            'icons_cache' => 'Caching icons',
            'filament_optimize' => 'Optimizing Filament',
        ];

        $completedTasks = $_SESSION['installer']['completed_tasks'] ?? [];

        $taskList = '';
        foreach ($tasks as $key => $label) {
            $status = in_array($key, $completedTasks) ? 'done' : 'pending';
            $taskList .= "<div class=\"task-item flex items-center gap-4 p-4 rounded-xl border border-slate-200 dark:border-slate-700 transition-all bg-white dark:bg-slate-800\" data-task=\"{$key}\" data-status=\"{$status}\">";
            $taskList .= '<span class="task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500"></span>';
            $taskList .= "<span class=\"task-label flex-1 text-sm font-medium text-slate-900 dark:text-white\">{$label}</span>";
            $taskList .= '<span class="task-message text-xs text-red-600 dark:text-red-400 text-right font-medium max-w-[50%]"></span>';
            $taskList .= '</div>';
        }

        $tasksJson = json_encode(array_keys($tasks));

        if (! isset($_SESSION['installer']['optimize_token'])) {
            $_SESSION['installer']['optimize_token'] = bin2hex(random_bytes(32));
        }

        $optimizeToken = $_SESSION['installer']['optimize_token'];

        $cleanProcessTasks = json_encode([
            'config_clear' => 'config:clear',
            'package_discover' => 'package:discover',
            'config_cache' => 'config:cache',
            'event_cache' => 'event:cache',
            'route_cache' => 'route:cache',
            'view_cache' => 'view:cache',
            'icons_cache' => 'icons:cache',
            'filament_optimize' => 'filament:optimize',
        ]);

        $appFolder = APP_FOLDER;

        $content = <<<HTML
        <p class="text-slate-600 dark:text-slate-400 mb-6">Installing your application. Please do not close this page.</p>
        
        <!-- Progress Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-sky-500 to-cyan-500 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center">
                            <span class="text-white text-xl">⚡</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">Installation Progress</h3>
                            <p class="text-sky-100 text-sm">Step 7 of 9</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-white" id="progress-percent">0%</div>
                        <div class="text-sky-100 text-sm">Complete</div>
                    </div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="h-2 bg-slate-100 dark:bg-slate-700">
                <div id="progress-bar" class="h-full bg-gradient-to-r from-sky-500 to-cyan-500 transition-all duration-500" style="width: 0%"></div>
            </div>

            <!-- Task List -->
            <div class="p-6 space-y-3" id="task-list">{$taskList}</div>
        </div>

        <div id="install-error" class="hidden bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <span class="text-red-500 text-lg">✕</span>
            </div>
            <div>
                <h3 class="font-semibold text-red-900 dark:text-red-400">Installation Failed</h3>
                <p class="text-sm text-red-700 dark:text-red-300" id="error-message"></p>
            </div>
        </div>

        <div class="actions hidden flex justify-between items-center gap-3" id="install-actions">
            <button type="button" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all hidden" id="retry-btn">Retry</button>
            <a href="install.php?step=8" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all hidden" id="continue-btn">Continue →</a>
        </div>
        <script>
            var tasks = {$tasksJson};
            var cleanProcessTasks = {$cleanProcessTasks};
            var optimizeToken = '{$optimizeToken}';
            var currentTaskIndex = 0;

            document.querySelectorAll('.task-item[data-status="done"]').forEach(function(el) {
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✓';
                icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                currentTaskIndex++;
            });

            function updateProgress(percent) {
                document.getElementById('progress-percent').textContent = Math.round(percent) + '%';
                document.getElementById('progress-bar').style.width = percent + '%';
            }

            function runTasks(fullReset) {
                document.getElementById('install-error').classList.add('hidden');
                document.getElementById('retry-btn').classList.add('hidden');

                if (fullReset) {
                    currentTaskIndex = 0;
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        var icon = el.querySelector('.task-icon');
                        el.classList.remove('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400', 'bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400', 'bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                        icon.innerHTML = '';
                        icon.className = 'task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500';
                        el.querySelector('.task-label').classList.remove('text-emerald-600', 'dark:text-emerald-400', 'text-red-600', 'dark:text-red-400', 'text-sky-600', 'dark:text-sky-400');
                        el.querySelector('.task-message').textContent = '';
                    });
                    updateProgress(0);
                    fetch('install.php?step=7&reset=1', { method: 'POST' }).then(function() { runNextTask(); });
                } else {
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        var icon = el.querySelector('.task-icon');
                        if (!icon.innerHTML.includes('✓')) {
                            el.classList.remove('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400', 'bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                            icon.className = 'task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500';
                            icon.innerHTML = '';
                            el.querySelector('.task-label').classList.remove('text-red-600', 'dark:text-red-400', 'text-sky-600', 'dark:text-sky-400');
                            el.querySelector('.task-message').textContent = '';
                        }
                    });
                    runNextTask();
                }
            }

            function runSeedBatch(el) {
                el.classList.add('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '<svg class="w-5 h-5 text-sky-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                icon.classList.add('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-sky-600', 'dark:text-sky-400');
                fetch('install.php?step=7&task=seed_batch', { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.seed_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            runSeedBatch(el);
                        } else if (data.success) {
                            el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                            icon.innerHTML = '✓';
                            icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                            icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                            el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                            el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                            el.querySelector('.task-message').textContent = '';
                            currentTaskIndex++;
                            updateProgress((currentTaskIndex / tasks.length) * 100);
                            runNextTask();
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                        icon.innerHTML = '✕';
                        icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                        icon.classList.add('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400');
                        el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                        el.querySelector('.task-label').classList.add('text-red-600', 'dark:text-red-400');
                        document.getElementById('install-error').classList.remove('hidden');
                        document.getElementById('error-message').textContent = 'Installation failed: ' + err.message;
                        document.getElementById('install-actions').classList.remove('hidden');
                        document.getElementById('install-actions').classList.add('flex');
                        document.getElementById('retry-btn').classList.remove('hidden');
                    });
            }

            function parseJsonResponse(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 500));
                    });
                }
                return r.json();
            }

            function handleTaskError(el, message) {
                el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✕';
                icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                icon.classList.add('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400');
                el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-red-600', 'dark:text-red-400');
                el.querySelector('.task-message').textContent = message || '';
                document.getElementById('install-error').classList.remove('hidden');
                document.getElementById('error-message').textContent = 'Installation failed: ' + message;
                document.getElementById('install-actions').classList.remove('hidden');
                document.getElementById('install-actions').classList.add('flex');
                document.getElementById('retry-btn').classList.remove('hidden');
            }

            function handleTaskSuccess(el) {
                el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✓';
                icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                el.querySelector('.task-message').textContent = '';
                currentTaskIndex++;
                updateProgress((currentTaskIndex / tasks.length) * 100);
                runNextTask();
            }

            function runNextTask() {
                if (currentTaskIndex >= tasks.length) {
                    document.getElementById('install-actions').classList.remove('hidden');
                    document.getElementById('install-actions').classList.add('flex');
                    document.getElementById('continue-btn').classList.remove('hidden');
                    return;
                }

                var task = tasks[currentTaskIndex];
                var el = document.querySelector('.task-item[data-task="' + task + '"]');
                el.classList.add('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '<svg class="w-5 h-5 text-sky-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                icon.classList.add('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-sky-600', 'dark:text-sky-400');

                if (cleanProcessTasks.hasOwnProperty(task)) {
                    fetch('install.php?step=7&task=' + task, { method: 'POST' })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) {
                                throw new Error(data.message);
                            }
                            var command = cleanProcessTasks[task];
                            return fetch('{$appFolder}/public/install-optimize.php?command=' + encodeURIComponent(command) + '&token=' + encodeURIComponent(optimizeToken)).then(parseJsonResponse);
                        })
                        .then(function(data) {
                            if (data.success) {
                                handleTaskSuccess(el);
                            } else {
                                handleTaskError(el, data.message);
                            }
                        })
                        .catch(function(err) {
                            handleTaskError(el, err.message);
                        });
                    return;
                }

                fetch('install.php?step=7&task=' + task, { method: 'POST' })
                    .then(parseJsonResponse)
                    .then(function(data) {
                        el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                        if (data.success && data.extract_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            updateProgress(data.percent || 0);
                            runNextTask();
                        } else if (data.success && data.seed_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            runSeedBatch(el);
                            return;
                        } else if (data.success) {
                            handleTaskSuccess(el);
                        } else {
                            handleTaskError(el, data.message);
                        }
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message);
                    });
            }

            if (currentTaskIndex < tasks.length) {
                runTasks(true);
            } else {
                document.getElementById('install-actions').classList.remove('hidden');
                document.getElementById('install-actions').classList.add('flex');
                document.getElementById('continue-btn').classList.remove('hidden');
            }
        </script>
HTML;

        $this->renderLayout('Installing', $content, 7);
    }

    private function renderCron(): void
    {
        $appPath = realpath(__DIR__.'/'.APP_FOLDER) ?: __DIR__.'/'.APP_FOLDER;
        $phpBinary = PHP_BINARY ?: '/usr/bin/php';
        $cronCommand = "* * * * * {$phpBinary} {$appPath}/artisan schedule:run >> /dev/null 2>&1";

        $content = <<<HTML
        <p class="text-slate-600 dark:text-slate-400 mb-6">Your application requires a scheduled task (cron job) to run background processes such as sending emails, expiring memberships, and running health checks.</p>

        <!-- Cron Job Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">⏰</span>
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">Cron Job Setup</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Add this cron job to your server</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <div class="bg-slate-900 dark:bg-black rounded-xl p-4 mb-4 border border-slate-700">
                    <code class="text-emerald-400 text-sm font-mono break-all">{$cronCommand}</code>
                </div>
                <button type="button" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium border border-slate-300 dark:border-slate-600 hover:bg-slate-200 dark:hover:bg-slate-600 transition-all text-sm" onclick="navigator.clipboard.writeText(document.querySelector('.bg-slate-900 code').textContent).then(function(){this.textContent='Copied!';}.bind(this))">📋 Copy to Clipboard</button>
            </div>
        </div>

        <!-- How to Add Instructions -->
        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
            How to Add a Cron Job
        </h3>
        <div class="space-y-3 mb-6">
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    cPanel
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Log in to cPanel and find "Cron Jobs" under "Advanced"</li>
                    <li>Set the timing to "Once Per Minute" (or <code class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-900 dark:text-white font-mono text-xs">* * * * *</code>)</li>
                    <li>Paste the command above into the "Command" field</li>
                    <li>Click "Add New Cron Job"</li>
                </ol>
            </details>
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    Plesk
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Go to "Scheduled Tasks" in your Plesk panel</li>
                    <li>Click "Add Task"</li>
                    <li>Set it to run every minute</li>
                    <li>Paste the command above</li>
                </ol>
            </details>
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    SSH / Terminal
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Connect to your server via SSH</li>
                    <li>Run <code class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-900 dark:text-white font-mono text-xs">crontab -e</code></li>
                    <li>Add the command above as a new line</li>
                    <li>Save and exit</li>
                </ol>
            </details>
        </div>

        <!-- Actions -->
        <div class="flex justify-end">
            <a href="install.php?step=9" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</a>
        </div>
HTML;

        $this->renderLayout('Cron Job Setup', $content, 8);
    }

    private function renderComplete(): void
    {
        $appUrl = $_SESSION['installer']['settings']['app_url'] ?? '';
        $adminEmail = htmlspecialchars($_SESSION['installer']['admin']['email'] ?? '');

        $deletionMessages = '';
        $zipPath = __DIR__.'/'.ZIP_FILENAME;
        $installPath = __DIR__.'/install.php';

        $zipDeleted = false;
        $installDeleted = false;

        if (file_exists($zipPath)) {
            $zipDeleted = @unlink($zipPath);
        } else {
            $zipDeleted = true;
        }

        if (! $zipDeleted) {
            $deletionMessages .= '<div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-4 mb-6 flex items-start gap-3">
                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                    <span class="text-yellow-500 text-lg">⚠️</span>
                </div>
                <div>
                    <h3 class="font-semibold text-yellow-900 dark:text-yellow-400">Manual Cleanup Required</h3>
                    <p class="text-sm text-yellow-700 dark:text-yellow-300">Could not automatically delete <strong>'.ZIP_FILENAME.'</strong>. Please delete it manually for security.</p>
                </div>
            </div>';
        }

        $this->cleanupOptimizerEndpoint();

        $selfDeleteScript = __DIR__.'/_cleanup.php';
        $loginUrl = rtrim($appUrl, '/').'/login';
        file_put_contents($selfDeleteScript, '<?php @unlink(__DIR__ . "/install.php"); @unlink(__DIR__ . "/'.APP_FOLDER.'/public/install-optimize.php"); @unlink(__FILE__); header("Location: '.$loginUrl.'"); exit;');

        $cleanupUrl = htmlspecialchars(dirname($_SERVER['SCRIPT_NAME']).'/_cleanup.php');
        $cleanupUrl = str_replace('//', '/', $cleanupUrl);

        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));

        $content = <<<HTML
        <!-- Success Icon -->
        <div class="flex justify-center mb-6">
            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-500 to-green-500 flex items-center justify-center shadow-2xl shadow-emerald-500/30 animate-bounce">
                <span class="text-4xl">✓</span>
            </div>
        </div>

        <!-- Success Message -->
        <div class="text-center mb-8">
            <h2 class="text-2xl md:text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 mb-2">Installation Complete!</h2>
            <p class="text-slate-600 dark:text-slate-400">Your {$appName} application has been successfully installed.</p>
        </div>

        {$deletionMessages}

        <!-- Admin Credentials -->
        <div class="bg-gradient-to-r from-blue-50 to-sky-50 dark:from-blue-900/20 dark:to-sky-900/20 rounded-2xl p-6 mb-6 border-2 border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-bold text-blue-900 dark:text-blue-400 mb-4 flex items-center gap-2">
                <span class="w-1 h-6 bg-gradient-to-b from-blue-500 to-sky-500 rounded-full"></span>
                Admin Login Details
            </h3>
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">🔗</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Admin Login</p>
                        <a href="{$cleanupUrl}" class="text-blue-600 dark:text-blue-300 font-medium hover:underline">{$appUrl}/login</a>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">📧</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Email</p>
                        <p class="text-slate-700 dark:text-slate-300">{$adminEmail}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">🔒</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Password</p>
                        <p class="text-slate-700 dark:text-slate-300">(the password you entered during setup)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Warning -->
        <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <span class="text-yellow-500 text-lg">🔒</span>
            </div>
            <div>
                <h3 class="font-semibold text-yellow-900 dark:text-yellow-400">Important</h3>
                <p class="text-sm text-yellow-700 dark:text-yellow-300">For security, the installer files will be deleted when you proceed. If auto-deletion fails, please manually delete <code class="px-2 py-1 rounded bg-yellow-100 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono text-xs">install.php</code> and <code class="px-2 py-1 rounded bg-yellow-100 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono text-xs">{$zipPath}</code> from your server.</p>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end">
            <a href="{$cleanupUrl}" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-green-500 text-white font-semibold shadow-lg shadow-emerald-500/30 hover:shadow-xl hover:shadow-emerald-500/40 transform hover:-translate-y-0.5 transition-all flex items-center gap-2">
                <span>🚀</span> Go to Application →
            </a>
        </div>
HTML;

        session_destroy();

        $this->renderLayout('Installation Complete', $content, 9);
    }

    private function renderAlreadyInstalled(): void
    {
        $content = <<<'HTML'
        <!-- Warning Alert -->
        <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-6 flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <span class="text-yellow-500 text-2xl">⚠️</span>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-bold text-yellow-900 dark:text-yellow-400 mb-2">Already Installed</h3>
                <p class="text-yellow-800 dark:text-yellow-300 mb-2">This application appears to already be installed. For security reasons, the installer cannot be run again.</p>
                <p class="text-sm text-yellow-700 dark:text-yellow-400">Please delete <code class="px-2 py-1 rounded bg-yellow-200 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono">install.php</code> from your server immediately.</p>
            </div>
        </div>
HTML;

        $this->renderLayout('Already Installed', $content, 0);
    }

    // ========================================================================
    // HTML Helpers
    // ========================================================================

    private function renderErrors(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        $html = '<div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6"><ul class="space-y-2">';
        foreach ($this->errors as $error) {
            $html .= '<li class="flex items-start gap-2 text-red-800 dark:text-red-300"><span class="text-red-500 mt-0.5">•</span>'.htmlspecialchars($error).'</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    private function getSidebarInfo(int $step): array
    {
        return match ($step) {
            1 => [
                'icon' => '📄',
                'desc' => 'Please read and accept the End User License Agreement before proceeding with the installation.',
                'label' => 'Step 1 of 6',
            ],
            2 => [
                'icon' => '🔍',
                'desc' => 'Verifying that your server meets all the requirements needed to run successfully.',
                'label' => 'Step 2 of 6',
            ],
            3 => [
                'icon' => '🗄️',
                'desc' => 'Enter the credentials for the database that you will use to store data.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            4 => [
                'icon' => '⚙️',
                'desc' => 'Configure your application name, URL, timezone, and the amount of initial data to load.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            5 => [
                'icon' => '✉️',
                'desc' => 'Set up how this application sends email notifications. You can always update this later in the admin panel.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            6 => [
                'icon' => '👤',
                'desc' => 'Create the administrator account you will use to manage your website.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            7 => [
                'icon' => '🚀',
                'desc' => 'Sit tight — The application is being installed on your server. Do not close this page.',
                'label' => 'Step 4 of 6',
            ],
            8 => [
                'icon' => '⏰',
                'desc' => 'Set up a scheduled task so you can run background jobs automatically.',
                'label' => 'Step 5 of 6',
            ],
            9 => [
                'icon' => '🎉',
                'desc' => 'Installation is complete. Your application is ready to go!',
                'label' => 'Step 6 of 6',
            ],
            default => ['icon' => '🐾', 'desc' => '', 'label' => ''],
        };
    }

    private function renderLayout(string $title, string $content, int $currentStep): void
    {
        $stepIndicator = $this->renderStepIndicator($currentStep);
        $subStepIndicator = $this->renderSubStepIndicator($currentStep);
        $version = INSTALLER_VERSION;
        $sidebar = $this->getSidebarInfo($currentStep);
        $sidebarIcon = $sidebar['icon'];
        $sidebarDesc = htmlspecialchars($sidebar['desc']);
        $sidebarLabel = htmlspecialchars($sidebar['label']);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Installer - {$title}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
                    },
                    animation: {
                        'spin': 'spin 1s linear infinite',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'bounce-slow': 'bounce 2s infinite',
                    },
                }
            }
        }
    </script>
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-25%); }
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-100 to-sky-50 dark:from-slate-900 dark:to-slate-800 text-slate-900 dark:text-slate-100 font-sans transition-colors duration-300">
    <div class="max-w-4xl mx-auto p-6 md:p-8">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-500 mb-4 shadow-lg shadow-sky-500/30">
                <span class="text-3xl">{$sidebarIcon}</span>
            </div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white mb-2">{$title}</h1>
            <p class="text-slate-500 dark:text-slate-400">{$sidebarDesc}</p>
            <p class="text-xs font-semibold text-sky-500 dark:text-sky-400 uppercase tracking-wide mt-2">{$sidebarLabel}</p>
        </div>

        {$stepIndicator}
        {$subStepIndicator}

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 p-6 md:p-8">
            {$content}
        </div>

        <div class="text-center mt-6 text-xs text-slate-400 dark:text-slate-500">Application Installer v{$version}</div>
    </div>
</body>
</html>
HTML;
    }

    private function renderInstallTimer(int $currentStep): string
    {
        if ($currentStep !== 7) {
            return '';
        }

        return <<<'HTML'
        <div class="flex justify-center flex-wrap gap-4 md:gap-8 mb-6 p-3 md:p-4 bg-slate-100 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-sm" id="install-timer">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Started:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-start">--:--:--</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Elapsed:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-elapsed">0s</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Remaining:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-remaining">Calculating...</span>
            </div>
        </div>
        <script>
            (function() {
                var startTime = Date.now();
                var startDate = new Date(startTime);
                var startFormatted = startDate.toLocaleTimeString();
                document.getElementById('timer-start').textContent = startFormatted;

                function formatDuration(ms) {
                    var totalSeconds = Math.floor(ms / 1000);
                    if (totalSeconds < 60) {
                        return totalSeconds + 's';
                    }
                    var minutes = Math.floor(totalSeconds / 60);
                    var seconds = totalSeconds % 60;
                    if (minutes < 60) {
                        return minutes + 'm ' + seconds + 's';
                    }
                    var hours = Math.floor(minutes / 60);
                    minutes = minutes % 60;
                    return hours + 'h ' + minutes + 'm ' + seconds + 's';
                }

                function getProgress() {
                    var totalTasks = typeof tasks !== 'undefined' ? tasks.length : 0;
                    if (totalTasks === 0) {
                        return 0;
                    }
                    var completed = document.querySelectorAll('.task-item.task-done').length;
                    return completed / totalTasks;
                }

                var timerInterval = setInterval(function() {
                    var elapsed = Date.now() - startTime;
                    document.getElementById('timer-elapsed').textContent = formatDuration(elapsed);

                    var progress = getProgress();
                    var remainingEl = document.getElementById('timer-remaining');

                    if (progress >= 1) {
                        remainingEl.textContent = 'Done';
                        clearInterval(timerInterval);
                    } else if (progress > 0.05) {
                        var estimatedTotal = elapsed / progress;
                        var remaining = estimatedTotal - elapsed;
                        remainingEl.textContent = '~' + formatDuration(remaining);
                    } else {
                        remainingEl.textContent = 'Calculating...';
                    }
                }, 1000);
            })();
        </script>
HTML;
    }

    private function renderStepIndicator(int $currentStep): string
    {
        if ($currentStep === 0) {
            return '';
        }

        $html = '<div class="flex justify-center flex-wrap gap-2 mb-6">';
        $visualNum = 0;
        foreach ($this->stepNames as $num => $name) {
            $visualNum++;
            $isSettingsGroup = ($num === 3);

            if ($isSettingsGroup) {
                if ($currentStep > 6) {
                    $class = 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
                    $icon = '✓';
                } elseif (in_array($currentStep, $this->settingsSubSteps)) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = $visualNum;
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = $visualNum;
                }
            } else {
                if ($num < $currentStep) {
                    $class = 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
                    $icon = '✓';
                } elseif ($num === $currentStep) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = $visualNum;
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = $visualNum;
                }
            }

            $html .= "<div class=\"flex items-center gap-2 px-3 py-1.5 rounded-full {$class} text-sm font-semibold\">{$icon}. {$name}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    private function renderSubStepIndicator(int $currentStep): string
    {
        if (! in_array($currentStep, $this->settingsSubSteps)) {
            return '';
        }

        $html = '<div class="flex justify-center flex-wrap gap-2 mb-6">';
        foreach ($this->settingsSubSteps as $index => $step) {
            if ($step < $currentStep) {
                $class = 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700';
                $icon = '✓';
            } elseif ($step === $currentStep) {
                $class = 'bg-sky-500 text-white shadow-lg shadow-sky-500/20';
                $icon = $index + 1;
            } else {
                $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                $icon = $index + 1;
            }
            $name = $this->settingsSubStepNames[$step];
            $num = $index + 1;
            $html .= "<div class=\"flex items-center gap-1.5 px-3 py-1.5 rounded-full {$class} text-xs font-semibold\">{$icon}. {$name}</div>";
        }
        $html .= '</div>';

        return $html;
    }
}

// ============================================================================
// Run the installer
// ============================================================================

/**
 * Render a clean, standalone error page when an unhandled exception
 * escapes the installer. Provides the full error detail in a copy-
 * pasteable block and directs the user to raise a support ticket.
 */
function renderFatalErrorPage(Throwable $e): void
{
    // If headers haven't been sent yet, set a 500 status.
    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $version = defined('INSTALLER_VERSION') ? htmlspecialchars(INSTALLER_VERSION) : 'unknown';
    $phpVersion = htmlspecialchars(PHP_VERSION);
    $timestamp = date('Y-m-d H:i:s T');
    $url = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $errorClass = htmlspecialchars(get_class($e));
    $message = htmlspecialchars($e->getMessage());
    $file = htmlspecialchars($e->getFile());
    $line = (int) $e->getLine();

    // Build the plain-text error block the user can copy
    $traceLines = [];
    foreach ($e->getTrace() as $i => $frame) {
        $f = $frame['file'] ?? '[internal]';
        $l = $frame['line'] ?? 0;
        $fn = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
        $traceLines[] = "#{$i} {$f}:{$l} {$fn}()";
        if ($i >= 9) { // limit trace depth for readability
            $traceLines[] = '... (truncated)';
            break;
        }
    }
    $traceText = implode("\n", $traceLines);

    $copyBlock = htmlspecialchars(
        "=== Application Installer Error ===\n"
        ."Timestamp:  {$timestamp}\n"
        ."URL:        {$url}\n"
        ."Installer:  v{$version}\n"
        ."PHP:        {$phpVersion}\n"
        ."Error:      {$errorClass}\n"
        ."Message:    {$e->getMessage()}\n"
        ."File:       {$file}\n"
        ."Line:       {$line}\n"
        ."\nStack Trace:\n{$traceText}\n"
        ."=====================================\n"
    );

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Error — Application Installer</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: 1.6;
        }
        .wrapper {
            width: 100%;
            max-width: 860px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            color: #475569;
        }
        .brand-icon {
            font-size: 1.5rem;
        }
        .card {
            background: #ffffff;
            border: 1.5px solid #fecaca;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0 0 4px rgba(239,68,68,0.08), 0 20px 40px rgba(0,0,0,0.08);
        }
        .card-header {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid #fecaca;
        }
        .card-header .icon {
            font-size: 2rem;
            flex-shrink: 0;
        }
        .card-header h1 {
            font-size: 1.375rem;
            font-weight: 800;
            color: #991b1b;
            letter-spacing: -0.02em;
        }
        .card-header p {
            font-size: 0.875rem;
            color: #b91c1c;
            margin-top: 0.25rem;
        }
        .card-body {
            padding: 1.75rem 2rem;
        }
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 0.625rem;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 0.375rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .meta-grid dt {
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
        }
        .meta-grid dd {
            color: #0f172a;
            word-break: break-word;
        }
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            font-size: 0.9375rem;
            color: #b91c1c;
            font-weight: 600;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        .copy-section {
            margin-bottom: 1.75rem;
        }
        .copy-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.625rem;
        }
        .copy-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            color: #475569;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.375rem 0.875rem;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .copy-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .copy-btn.copied {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }
        .error-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.625rem;
            padding: 1rem 1.25rem;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.8125rem;
            color: #475569;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 320px;
            overflow-y: auto;
            line-height: 1.7;
        }
        .support-box {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .support-box .support-icon {
            font-size: 1.75rem;
            flex-shrink: 0;
            line-height: 1;
            margin-top: 0.125rem;
        }
        .support-box h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #1d4ed8;
            margin-bottom: 0.375rem;
        }
        .support-box p {
            font-size: 0.875rem;
            color: #475569;
            margin-bottom: 0.625rem;
        }
        .support-link {
            display: inline-block;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.875rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: opacity 0.15s;
        }
        .support-link:hover {
            opacity: 0.85;
        }
        .footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }
        @media (max-width: 600px) {
            .card-body { padding: 1.25rem; }
            .card-header { padding: 1.25rem; }
            .meta-grid { grid-template-columns: 1fr; gap: 0.25rem; }
            .meta-grid dt { margin-top: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="brand">
            <span class="brand-icon">🐾</span>
            Application Installer v{$version}
        </div>

        <div class="card">
            <div class="card-header">
                <div class="icon">🚨</div>
                <div>
                    <h1>An Unexpected Error Occurred</h1>
                    <p>The installer encountered an unhandled exception and could not continue.</p>
                </div>
            </div>

            <div class="card-body">
                <p class="section-title">Error Details</p>
                <dl class="meta-grid">
                    <dt>Type</dt><dd>{$errorClass}</dd>
                    <dt>File</dt><dd>{$file} (line {$line})</dd>
                    <dt>PHP</dt><dd>{$phpVersion}</dd>
                    <dt>Time</dt><dd>{$timestamp}</dd>
                </dl>

                <div class="error-message">{$message}</div>

                <div class="copy-section">
                    <div class="copy-header">
                        <p class="section-title" style="margin:0;">Full Error Report — Copy This When Raising a Ticket</p>
                        <button class="copy-btn" id="copy-btn" onclick="copyError()">Copy to Clipboard</button>
                    </div>
                    <pre class="error-block" id="error-block">{$copyBlock}</pre>
                </div>

                <div class="support-box">
                    <div class="support-icon">💬</div>
                    <div>
                        <h3>Need Help?</h3>
                        <p>
                            Copy the error report above and raise a support ticket. Our team will help
                            you resolve the issue as quickly as possible.
                        </p>
                        <a href="https://support.powerphpscripts.com" target="_blank" rel="noopener noreferrer" class="support-link">
                            Raise a Support Ticket &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">Application Installer v{$version} &mdash; <a href="https://support.powerphpscripts.com" target="_blank" rel="noopener noreferrer" style="color:#334155;">support.powerphpscripts.com</a></div>
    </div>

    <script>
        function copyError() {
            var text = document.getElementById('error-block').textContent;
            var btn  = document.getElementById('copy-btn');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = 'Copy to Clipboard';
                        btn.classList.remove('copied');
                    }, 2500);
                }).catch(function() { fallbackCopy(text, btn); });
            } else {
                fallbackCopy(text, btn);
            }
        }
        function fallbackCopy(text, btn) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = 'Copy to Clipboard';
                    btn.classList.remove('copied');
                }, 2500);
            } catch(e) {}
            document.body.removeChild(ta);
        }
    </script>
</body>
</html>
HTML;
}

try {
    (new Installer)->run();
} catch (Throwable $e) {
    renderFatalErrorPage($e);
}
