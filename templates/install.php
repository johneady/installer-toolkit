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

define('ZIP_FILENAME', 'pet-adoption.zip');
define('APP_FOLDER', 'pet-adoption');
define('MIN_PHP_VERSION', '8.2.0');
define('INSTALLER_VERSION', '2.0.3');

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
        $name = trim($_POST['admin_name'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $passwordConfirm = $_POST['admin_password_confirm'] ?? '';

        if ($name === '') {
            $this->errors[] = 'Name is required.';
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
            'name' => $name,
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
        $optionalExtensions = ['imagick', 'gmp'];
        foreach ($optionalExtensions as $ext) {
            $results[] = [
                'name' => "PHP Extension: {$ext} (optional)",
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded — fallback available',
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
            'detail' => $modRewrite['detail'],
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
                : 'Disabled via disable_functions: '.implode(', ', $blockedFunctions),
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
FIRST_USER_NAME="{$this->escapeEnvValue($admin['name'])}"
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
            <div class="eula-box">{$eula}</div>
            <label class="checkbox-label">
                <input type="checkbox" name="accept_eula" value="1" id="accept-eula">
                <span>I have read and agree to the End User License Agreement</span>
            </label>
            <div class="actions">
                <button type="submit" class="btn btn-primary" id="accept-btn" disabled>I Accept</button>
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

        $items = '';
        foreach ($results as $r) {
            $icon = $r['passed'] ? '<span class="status-pass">&#10004;</span>' : ($r['critical'] ? '<span class="status-fail">&#10008;</span>' : '<span class="status-warn">&#9888;</span>');
            $statusClass = $r['passed'] ? 'req-passed' : ($r['critical'] ? 'req-failed' : 'req-warn');
            $items .= "<div class=\"req-item {$statusClass}\">{$icon} <span class=\"req-name\">{$r['name']}</span><span class=\"req-detail\">{$r['detail']}</span></div>";
            if (! $r['passed'] && $r['critical']) {
                $allCriticalPassed = false;
            }
        }

        $disabled = $allCriticalPassed ? '' : 'disabled';
        $retestButton = $allCriticalPassed ? '' : '<a href="install.php?step=2" class="btn btn-secondary">Re-Test</a>';
        $warning = $allCriticalPassed ? '' : '<div class="alert alert-error">Some critical requirements are not met. Please resolve them before continuing.</div>';

        $content = <<<HTML
        {$warning}
        <div class="requirements-grid">{$items}</div>
        <form method="POST" action="install.php?step=2">
            <div class="actions">
                <a href="install.php?step=1" class="btn btn-secondary">Back</a>
                {$retestButton}
                <button type="submit" class="btn btn-primary" {$disabled}>Continue</button>
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
        <div id="db-test-result" style="display:none;"></div>
        <form method="POST" action="install.php?step=3" id="db-form">
            <div class="form-grid">
                <div class="form-group">
                    <label for="db_host">Database Host <span class="required">*</span></label>
                    <input type="text" name="db_host" id="db_host" value="{$host}" required>
                </div>
                <div class="form-group">
                    <label for="db_port">Database Port</label>
                    <input type="text" name="db_port" id="db_port" value="{$port}">
                </div>
                <div class="form-group">
                    <label for="db_name">Database Name <span class="required">*</span></label>
                    <input type="text" name="db_name" id="db_name" value="{$name}" required>
                </div>
                <div class="form-group">
                    <label for="db_user">Database Username <span class="required">*</span></label>
                    <input type="text" name="db_user" id="db_user" value="{$user}" required>
                </div>
                <div class="form-group full-width">
                    <label for="db_pass">Database Password</label>
                    <input type="password" name="db_pass" id="db_pass" value="{$pass}">
                </div>
            </div>
            <div class="actions">
                <a href="install.php?step=2" class="btn btn-secondary">Back</a>
                <button type="button" class="btn btn-outline" id="test-db-btn">Test Connection</button>
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>
        <script>
            document.getElementById('test-db-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('db-test-result');
                btn.disabled = true;
                btn.textContent = 'Testing...';
                resultDiv.style.display = 'none';

                var formData = new FormData(document.getElementById('db-form'));

                fetch('install.php?step=3&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = data.success ? 'alert alert-success' : 'alert alert-error';
                    resultDiv.textContent = data.message;
                    btn.disabled = false;
                    btn.textContent = 'Test Connection';
                })
                .catch(function(err) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'alert alert-error';
                    resultDiv.textContent = 'An error occurred while testing the connection.';
                    btn.disabled = false;
                    btn.textContent = 'Test Connection';
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

        // Auto-detect app URL
        $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $defaultUrl = $protocol.'://'.$host;

        $appName = htmlspecialchars($s['app_name'] ?? ucwords(str_replace(['-', '_'], ' ', APP_FOLDER)));
        $appUrl = htmlspecialchars($s['app_url'] ?? $defaultUrl);
        $timezone = $s['timezone'] ?? 'America/Toronto';
        $sampleData = $s['sample_data'] ?? 'essential';
        $essentialChecked = $sampleData === 'essential' ? ' checked' : '';
        $fullChecked = $sampleData === 'full' ? ' checked' : '';

        // Build timezone options
        $timezones = DateTimeZone::listIdentifiers();
        $tzOptions = '';
        foreach ($timezones as $tz) {
            $selected = ($tz === $timezone) ? ' selected' : '';
            $tzOptions .= "<option value=\"{$tz}\"{$selected}>{$tz}</option>";
        }

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=4">
            <h3 class="section-title">Application</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="app_name">Application Name <span class="required">*</span></label>
                    <input type="text" name="app_name" id="app_name" value="{$appName}" required>
                </div>
                <div class="form-group">
                    <label for="app_url">Application URL <span class="required">*</span></label>
                    <input type="url" name="app_url" id="app_url" value="{$appUrl}" required>
                </div>
                <div class="form-group full-width">
                    <label for="timezone">Timezone <span class="required">*</span></label>
                    <select name="timezone" id="timezone" required>{$tzOptions}</select>
                </div>
            </div>

            <h3 class="section-title">Initial Data</h3>
            <p class="step-description" style="margin-bottom: 1rem;">Choose how much content to pre-populate your site with. You can always add your own data later.</p>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="radio-label">
                        <input type="radio" name="sample_data" value="essential"{$essentialChecked}>
                        <span>
                            <strong>Essentials only</strong>
                            <small class="form-hint" style="display:block;">Sets up core configuration, menus, and pages &mdash; a clean slate ready for your own affiliates and content.</small>
                        </span>
                    </label>
                </div>
                <div class="form-group full-width">
                    <label class="radio-label">
                        <input type="radio" name="sample_data" value="full"{$fullChecked}>
                        <span>
                            <strong>Full demonstration data</strong>
                            <small class="form-hint" style="display:block;">Includes sample affiliates, applications, blog posts, and more &mdash; ideal for exploring all features before going live.</small>
                        </span>
                    </label>
                </div>
            </div>

            <div class="actions">
                <a href="install.php?step=3" class="btn btn-secondary">Back</a>
                <button type="submit" class="btn btn-primary">Continue</button>
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
        $smtpDisplay = $mailMailer === 'smtp' ? '' : 'display:none;';
        $sendmailDisplay = $mailMailer === 'sendmail' ? '' : 'display:none;';
        $fromDisplay = $mailMailer === 'log' ? 'display:none;' : '';

        $content = <<<HTML
        {$errors}
        <div id="mail-test-result" style="display:none;"></div>
        <form method="POST" action="install.php?step=5" id="mail-form">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="mail_mailer">Mail Driver</label>
                    <select name="mail_mailer" id="mail_mailer">
                        <option value="smtp"{$smtpSelected}>SMTP</option>
                        <option value="sendmail"{$sendmailSelected}>Sendmail (PHP mail)</option>
                        <option value="log"{$logSelected}>Log (no emails sent)</option>
                    </select>
                    <small class="form-hint">Select "Log" if you want to configure email later.</small>
                </div>
            </div>
            <div id="smtp-fields" style="{$smtpDisplay}">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="mail_host">SMTP Host</label>
                        <input type="text" name="mail_host" id="mail_host" value="{$mailHost}" placeholder="smtp.example.com">
                    </div>
                    <div class="form-group">
                        <label for="mail_port">SMTP Port</label>
                        <input type="text" name="mail_port" id="mail_port" value="{$mailPort}" placeholder="587">
                    </div>
                    <div class="form-group">
                        <label for="mail_username">SMTP Username</label>
                        <input type="text" name="mail_username" id="mail_username" value="{$mailUsername}">
                    </div>
                    <div class="form-group">
                        <label for="mail_password">SMTP Password</label>
                        <input type="password" name="mail_password" id="mail_password" value="{$mailPassword}">
                    </div>
                </div>
            </div>
            <div id="sendmail-fields" style="{$sendmailDisplay}">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <small class="form-hint">Uses your server's built-in sendmail/PHP mail function. No additional server configuration needed.</small>
                    </div>
                </div>
            </div>
            <div id="from-fields" style="{$fromDisplay}">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="mail_from_address">From Address</label>
                        <input type="email" name="mail_from_address" id="mail_from_address" value="{$mailFromAddress}" placeholder="noreply@yourdomain.com">
                    </div>
                    <div class="form-group">
                        <label for="mail_from_name">From Name</label>
                        <input type="text" name="mail_from_name" id="mail_from_name" value="{$mailFromName}" placeholder="{$appName}">
                    </div>
                </div>
            </div>
            <div class="actions">
                <a href="install.php?step=4" class="btn btn-secondary">Back</a>
                <button type="button" class="btn btn-outline" id="test-mail-btn">Test Connection</button>
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>
        <script>
            document.getElementById('mail_mailer').addEventListener('change', function() {
                document.getElementById('smtp-fields').style.display = this.value === 'smtp' ? '' : 'none';
                document.getElementById('sendmail-fields').style.display = this.value === 'sendmail' ? '' : 'none';
                document.getElementById('from-fields').style.display = this.value === 'log' ? 'none' : '';
                document.getElementById('mail-test-result').style.display = 'none';
            });

            document.getElementById('test-mail-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('mail-test-result');
                btn.disabled = true;
                btn.textContent = 'Testing...';
                resultDiv.style.display = 'none';

                var formData = new FormData(document.getElementById('mail-form'));

                fetch('install.php?step=5&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = data.success ? 'alert alert-success' : 'alert alert-error';
                    resultDiv.textContent = data.message;
                    btn.disabled = false;
                    btn.textContent = 'Test Connection';
                })
                .catch(function(err) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'alert alert-error';
                    resultDiv.textContent = 'An error occurred while testing the mail connection.';
                    btn.disabled = false;
                    btn.textContent = 'Test Connection';
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

        $name = htmlspecialchars($admin['name'] ?? '');
        $email = htmlspecialchars($admin['email'] ?? '');

        $content = <<<HTML
        {$errors}
        <p class="step-description">Create your administrator account. You will use these credentials to log into the admin panel.</p>
        <form method="POST" action="install.php?step=6">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="admin_name">Name <span class="required">*</span></label>
                    <input type="text" name="admin_name" id="admin_name" value="{$name}" required>
                </div>
                <div class="form-group full-width">
                    <label for="admin_email">Email Address <span class="required">*</span></label>
                    <input type="email" name="admin_email" id="admin_email" value="{$email}" required>
                </div>
                <div class="form-group">
                    <label for="admin_password">Password <span class="required">*</span></label>
                    <input type="password" name="admin_password" id="admin_password" minlength="8" required>
                    <small class="form-hint">Minimum 8 characters</small>
                </div>
                <div class="form-group">
                    <label for="admin_password_confirm">Confirm Password <span class="required">*</span></label>
                    <input type="password" name="admin_password_confirm" id="admin_password_confirm" minlength="8" required>
                </div>
            </div>
            <div class="actions">
                <a href="install.php?step=5" class="btn btn-secondary">Back</a>
                <button type="submit" class="btn btn-primary">Continue</button>
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
            $taskList .= "<div class=\"task-item\" data-task=\"{$key}\" data-status=\"{$status}\">";
            $taskList .= '<span class="task-icon"></span>';
            $taskList .= "<span class=\"task-label\">{$label}</span>";
            $taskList .= '<span class="task-message"></span>';
            $taskList .= '</div>';
        }

        $tasksJson = json_encode(array_keys($tasks));

        // Pre-generate the optimizer token so JS can reference it
        if (! isset($_SESSION['installer']['optimize_token'])) {
            $_SESSION['installer']['optimize_token'] = bin2hex(random_bytes(32));
        }

        $optimizeToken = $_SESSION['installer']['optimize_token'];

        // Tasks that must run in a separate PHP process to avoid
        // stale in-memory environment from earlier Laravel boots.
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
        <p class="step-description">Installing your application. Please do not close this page.</p>
        <div class="task-list" id="task-list">{$taskList}</div>
        <div id="install-error" class="alert alert-error" style="display:none;"></div>
        <div class="actions" id="install-actions" style="display:none;">
            <button type="button" class="btn btn-primary" id="retry-btn" style="display:none;" onclick="runTasks(false)">Retry</button>
            <a href="install.php?step=8" class="btn btn-primary" id="continue-btn" style="display:none;">Continue</a>
        </div>
        <script>
            var tasks = {$tasksJson};
            var cleanProcessTasks = {$cleanProcessTasks};
            var optimizeToken = '{$optimizeToken}';
            var currentTaskIndex = 0;

            // Skip already completed tasks
            document.querySelectorAll('.task-item[data-status="done"]').forEach(function(el) {
                el.querySelector('.task-icon').innerHTML = '&#10004;';
                el.classList.add('task-done');
                currentTaskIndex++;
            });

            function runTasks(fullReset) {
                document.getElementById('install-error').style.display = 'none';
                document.getElementById('retry-btn').style.display = 'none';

                if (fullReset) {
                    // Full reset: restart everything from scratch
                    currentTaskIndex = 0;
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        el.classList.remove('task-done', 'task-error', 'task-running');
                        el.querySelector('.task-icon').innerHTML = '';
                        el.querySelector('.task-message').textContent = '';
                    });

                    fetch('install.php?step=7&reset=1', { method: 'POST' })
                        .then(function() { runNextTask(); })
                        .catch(function() { runNextTask(); });
                } else {
                    // Retry: resume from the failed task, keeping completed tasks
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        if (!el.classList.contains('task-done')) {
                            el.classList.remove('task-error', 'task-running');
                            el.querySelector('.task-icon').innerHTML = '';
                            el.querySelector('.task-message').textContent = '';
                        }
                    });

                    runNextTask();
                }
            }

            function runSeedBatch(el) {
                el.classList.add('task-running');
                el.querySelector('.task-icon').innerHTML = '<span class="spinner"></span>';
                fetch('install.php?step=7&task=seed_batch', { method: 'POST' })
                    .then(function(r) {
                        if (!r.ok) {
                            return r.text().then(function(text) {
                                throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 500));
                            });
                        }
                        return r.text().then(function(text) {
                            try { return JSON.parse(text); }
                            catch (e) { throw new Error('Invalid server response: ' + text.substring(0, 500)); }
                        });
                    })
                    .then(function(data) {
                        el.classList.remove('task-running');
                        if (data.success && data.seed_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            runSeedBatch(el);
                        } else if (data.success) {
                            el.classList.add('task-done');
                            el.querySelector('.task-icon').innerHTML = '&#10004;';
                            el.querySelector('.task-message').textContent = '';
                            currentTaskIndex++;
                            runNextTask();
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        el.classList.remove('task-running');
                        el.classList.add('task-error');
                        el.querySelector('.task-icon').innerHTML = '&#10008;';
                        document.getElementById('install-error').style.display = 'block';
                        document.getElementById('install-error').textContent = 'Installation failed: ' + err.message;
                        document.getElementById('install-actions').style.display = 'flex';
                        document.getElementById('retry-btn').style.display = '';
                    });
            }

            function parseJsonResponse(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 500));
                    });
                }
                return r.text().then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Invalid server response: ' + text.substring(0, 500));
                    }
                });
            }

            function handleTaskError(el, message) {
                el.classList.remove('task-running');
                el.classList.add('task-error');
                el.querySelector('.task-icon').innerHTML = '&#10008;';
                el.querySelector('.task-message').textContent = message || '';
                document.getElementById('install-error').style.display = 'block';
                document.getElementById('install-error').textContent = 'Installation failed: ' + message;
                document.getElementById('install-actions').style.display = 'flex';
                document.getElementById('retry-btn').style.display = '';
            }

            function handleTaskSuccess(el) {
                el.classList.remove('task-running');
                el.classList.add('task-done');
                el.querySelector('.task-icon').innerHTML = '&#10004;';
                el.querySelector('.task-message').textContent = '';
                currentTaskIndex++;
                runNextTask();
            }

            function runNextTask() {
                if (currentTaskIndex >= tasks.length) {
                    document.getElementById('install-actions').style.display = 'flex';
                    document.getElementById('continue-btn').style.display = '';
                    return;
                }

                var task = tasks[currentTaskIndex];
                var el = document.querySelector('.task-item[data-task="' + task + '"]');
                el.classList.add('task-running');
                el.querySelector('.task-icon').innerHTML = '<span class="spinner"></span>';

                // Tasks that need a clean PHP process are dispatched to the
                // standalone optimizer endpoint after the main installer
                // prepares the endpoint file.
                if (cleanProcessTasks.hasOwnProperty(task)) {
                    fetch('install.php?step=7&task=' + task, { method: 'POST' })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) {
                                throw new Error(data.message);
                            }
                            // Now run the actual command in a separate PHP process
                            var command = cleanProcessTasks[task];
                            return fetch('{$appFolder}/public/install-optimize.php?command=' + encodeURIComponent(command) + '&token=' + encodeURIComponent(optimizeToken))
                                .then(parseJsonResponse);
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
                        el.classList.remove('task-running');
                        if (data.success && data.extract_done === false) {
                            // Extraction in progress — update message and re-request
                            el.querySelector('.task-message').textContent = data.message || '';
                            runNextTask();
                        } else if (data.success && data.seed_done === false) {
                            // Seeding in progress — call seed_batch for remaining seeders
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

            // Auto-start installation
            if (currentTaskIndex < tasks.length) {
                runTasks(true);
            } else {
                document.getElementById('install-actions').style.display = 'flex';
                document.getElementById('continue-btn').style.display = '';
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
        <p class="step-description">Your application requires a scheduled task (cron job) to run background processes such as sending emails, expiring memberships, and running health checks.</p>

        <div class="cron-box">
            <label>Add this cron job to your server:</label>
            <div class="code-block" id="cron-command">{$cronCommand}</div>
            <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('cron-command').textContent).then(function(){this.textContent='Copied!'}.bind(this))">Copy to Clipboard</button>
        </div>

        <h3 class="section-title">How to Add a Cron Job</h3>
        <div class="help-section">
            <details>
                <summary>cPanel</summary>
                <ol>
                    <li>Log in to cPanel and find "Cron Jobs" under "Advanced"</li>
                    <li>Set the timing to "Once Per Minute" (or <code>* * * * *</code>)</li>
                    <li>Paste the command above into the "Command" field</li>
                    <li>Click "Add New Cron Job"</li>
                </ol>
            </details>
            <details>
                <summary>Plesk</summary>
                <ol>
                    <li>Go to "Scheduled Tasks" in your Plesk panel</li>
                    <li>Click "Add Task"</li>
                    <li>Set it to run every minute</li>
                    <li>Paste the command above</li>
                </ol>
            </details>
            <details>
                <summary>SSH / Terminal</summary>
                <ol>
                    <li>Connect to your server via SSH</li>
                    <li>Run <code>crontab -e</code></li>
                    <li>Add the command above as a new line</li>
                    <li>Save and exit</li>
                </ol>
            </details>
        </div>

        <div class="actions">
            <a href="install.php?step=9" class="btn btn-primary">Continue</a>
        </div>
HTML;

        $this->renderLayout('Cron Job Setup', $content, 8);
    }

    private function renderComplete(): void
    {
        $appUrl = $_SESSION['installer']['settings']['app_url'] ?? '';
        $adminEmail = htmlspecialchars($_SESSION['installer']['admin']['email'] ?? '');

        // Attempt to delete installer files
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
            $deletionMessages .= '<div class="alert alert-warning">Could not automatically delete <strong>'.ZIP_FILENAME.'</strong>. Please delete it manually for security.</div>';
        }

        // Clean up the optimizer endpoint if it still exists
        $this->cleanupOptimizerEndpoint();

        // We can't delete ourselves while executing, so we'll create a self-destruct mechanism
        $selfDeleteScript = __DIR__.'/_cleanup.php';
        $loginUrl = rtrim($appUrl, '/').'/login';
        file_put_contents($selfDeleteScript, '<?php @unlink(__DIR__ . "/install.php"); @unlink(__DIR__ . "/'.APP_FOLDER.'/public/install-optimize.php"); @unlink(__FILE__); header("Location: '.$loginUrl.'"); exit;');

        $cleanupUrl = htmlspecialchars(dirname($_SERVER['SCRIPT_NAME']).'/_cleanup.php');
        $cleanupUrl = str_replace('//', '/', $cleanupUrl);

        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));

        $content = <<<HTML
        <div class="success-icon">&#10004;</div>
        <h2 class="success-title">Installation Complete!</h2>
        <p class="success-message">Your {$appName} application has been successfully installed.</p>

        {$deletionMessages}

        <div class="info-box">
            <p><strong>Admin Login:</strong> <a href="{$cleanupUrl}">{$appUrl}/login</a></p>
            <p><strong>Email:</strong> {$adminEmail}</p>
            <p><strong>Password:</strong> (the password you entered during setup)</p>
        </div>

        <div class="alert alert-warning">
            <strong>Important:</strong> For security, the installer files will be deleted when you proceed. If auto-deletion fails, please manually delete <code>install.php</code> and <code>{$zipPath}</code> from your server.
        </div>

        <div class="actions">
            <a href="{$cleanupUrl}" class="btn btn-primary">Go to Application &rarr;</a>
        </div>
HTML;

        // Destroy session
        session_destroy();

        $this->renderLayout('Installation Complete', $content, 9);
    }

    private function renderAlreadyInstalled(): void
    {
        $content = <<<'HTML'
        <div class="alert alert-warning">
            <strong>Already Installed</strong>
            <p>This application appears to already be installed. For security reasons, the installer cannot be run again.</p>
            <p>Please delete <code>install.php</code> from your server immediately.</p>
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

        $html = '<div class="alert alert-error"><ul>';
        foreach ($this->errors as $error) {
            $html .= '<li>'.htmlspecialchars($error).'</li>';
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
    <style>
        /* CSS Custom Properties for Theming */
        :root {
            /* Light Theme Colors */
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f1f5f9;
            --bg-elevated: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-tertiary: #94a3b8;
            --text-muted: #64748b;
            --border-primary: #e2e8f0;
            --border-secondary: #cbd5e1;
            --border-focus: #0ea5e9;
            
            /* Brand Colors */
            --primary: #0ea5e9;
            --primary-hover: #0284c7;
            --primary-light: #e0f2fe;
            --primary-glow: rgba(14, 165, 233, 0.15);
            
            /* Status Colors */
            --success: #10b981;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --success-glow: rgba(16, 185, 129, 0.15);
            
            --error: #ef4444;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --error-glow: rgba(239, 68, 68, 0.15);
            
            --warning: #f59e0b;
            --warning-bg: #fffbeb;
            --warning-border: #fde68a;
            --warning-glow: rgba(245, 158, 11, 0.15);
            
            --info: #3b82f6;
            --info-bg: #eff6ff;
            --info-border: #bfdbfe;
            
            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            --shadow-glow: 0 0 20px rgba(14, 165, 233, 0.3);
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-card: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            
            /* Code Block */
            --code-bg: #1e293b;
            --code-text: #e2e8f0;
        }

        /* Dark Theme */
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-primary: #0f172a;
                --bg-secondary: #1e293b;
                --bg-tertiary: #334155;
                --bg-elevated: #1e293b;
                --text-primary: #f1f5f9;
                --text-secondary: #cbd5e1;
                --text-tertiary: #94a3b8;
                --text-muted: #64748b;
                --border-primary: #334155;
                --border-secondary: #475569;
                --border-focus: #38bdf8;
                
                --primary: #38bdf8;
                --primary-hover: #0ea5e9;
                --primary-light: rgba(56, 189, 248, 0.15);
                --primary-glow: rgba(56, 189, 248, 0.2);
                
                --success: #34d399;
                --success-bg: rgba(52, 211, 153, 0.1);
                --success-border: rgba(52, 211, 153, 0.3);
                --success-glow: rgba(52, 211, 153, 0.2);
                
                --error: #f87171;
                --error-bg: rgba(248, 113, 113, 0.1);
                --error-border: rgba(248, 113, 113, 0.3);
                --error-glow: rgba(248, 113, 113, 0.2);
                
                --warning: #fbbf24;
                --warning-bg: rgba(251, 191, 36, 0.1);
                --warning-border: rgba(251, 191, 36, 0.3);
                --warning-glow: rgba(251, 191, 36, 0.2);
                
                --info: #60a5fa;
                --info-bg: rgba(96, 165, 250, 0.1);
                --info-border: rgba(96, 165, 250, 0.3);
                
                --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 0 2px 4px -2px rgba(0, 0, 0, 0.4);
                --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.4), 0 4px 6px -4px rgba(0, 0, 0, 0.4);
                --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
                
                --gradient-card: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);
            }
        }

        /* Base Styles */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            padding: 2rem 1rem;
            transition: background 0.3s ease, color 0.3s ease;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: none;
        }

        .install-timer {
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 0.875rem 1.25rem;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            color: var(--text-secondary);
        }

        .install-timer .timer-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .install-timer .timer-label {
            font-weight: 600;
            color: var(--text-primary);
        }

        .install-timer .timer-value {
            font-variant-numeric: tabular-nums;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        .header .subtitle {
            font-size: 0.875rem;
            color: var(--text-tertiary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Step Indicator — now lives inside card-body */
        .steps {
            display: none;
        }

        .card-body-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-primary);
        }

        .card-body-header h1 {
            font-size: 1.375rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.875rem;
            letter-spacing: -0.02em;
        }

        .card-body-header .steps {
            display: flex;
            justify-content: flex-start;
            gap: 0.375rem;
            margin-bottom: 0;
            flex-wrap: wrap;
        }

        .step-dot {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-tertiary);
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            background: var(--bg-tertiary);
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border-primary);
        }

        .step-dot.active {
            background: var(--gradient-primary);
            color: #fff;
            font-weight: 700;
            box-shadow: var(--shadow-glow);
            border-color: transparent;
            transform: scale(1.05);
        }

        .step-dot.completed {
            background: var(--success-bg);
            color: var(--success);
            border-color: var(--success-border);
        }

        /* Sub-step Indicator */
        .sub-steps {
            display: flex;
            justify-content: center;
            gap: 0.375rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .sub-step-dot {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.6875rem;
            color: var(--text-tertiary);
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            background: var(--bg-tertiary);
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border-primary);
        }

        .sub-step-dot.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
            border-color: transparent;
        }

        .sub-step-dot.completed {
            background: var(--success-bg);
            color: var(--success);
            border-color: var(--success-border);
        }

        /* Card */
        /* Two-column card layout */
        .card {
            background: var(--gradient-card);
            border-radius: 1.25rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-primary);
            transition: all 0.3s ease;
            display: grid;
            grid-template-columns: 320px 1fr;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: var(--shadow-xl);
        }

        .card-sidebar {
            background: var(--gradient-primary);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            border-radius: 1.25rem 0 0 1.25rem;
        }

        .card-sidebar h2 {
            font-size: 1.625rem;
            font-weight: 700;
            color: #fff;
            margin: 0;
            line-height: 1.3;
        }

        .card-sidebar .sidebar-desc {
            font-size: 0.9375rem;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.7;
        }

        .card-sidebar .sidebar-icon {
            font-size: 3.25rem;
            line-height: 1;
        }

        .card-sidebar .sidebar-step {
            font-size: 0.8125rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255, 255, 255, 0.65);
            margin-top: auto;
        }

        .card-body {
            padding: 2.5rem;
            overflow: hidden;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            letter-spacing: 0.01em;
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--border-primary);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            background: var(--bg-secondary);
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: 0 0 0 4px var(--primary-glow);
            transform: translateY(-1px);
        }

        .form-group input::placeholder {
            color: var(--text-tertiary);
        }

        .form-hint {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            margin-top: 0.375rem;
            line-height: 1.4;
        }

        .required { color: var(--error); font-weight: 700; }

        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 2rem 0 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title::before {
            content: '';
            width: 4px;
            height: 1.5rem;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        /* Buttons */
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.875rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-primary);
        }

        .btn {
            padding: 0.75rem 1.75rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: 1.5px solid transparent;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            letter-spacing: 0.01em;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            box-shadow: 0 6px 20px rgba(14, 165, 233, 0.4);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border-color: var(--border-primary);
        }

        .btn-secondary:hover {
            background: var(--bg-tertiary);
            border-color: var(--border-secondary);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary-light);
            box-shadow: 0 0 0 4px var(--primary-glow);
        }

        .btn-sm {
            padding: 0.375rem 1rem;
            font-size: 0.8125rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: 1.5px solid;
        }

        .alert ul {
            margin: 0;
            padding-left: 1.25rem;
        }

        .alert-error {
            background: var(--error-bg);
            color: var(--error);
            border-color: var(--error-border);
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success);
            border-color: var(--success-border);
        }

        .alert-warning {
            background: var(--warning-bg);
            color: var(--warning);
            border-color: var(--warning-border);
        }

        /* EULA */
        .eula-box {
            background: var(--bg-tertiary);
            border: 1.5px solid var(--border-primary);
            border-radius: 0.75rem;
            padding: 1.25rem;
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.8125rem;
            line-height: 1.8;
            white-space: pre-wrap;
            margin-bottom: 1rem;
            color: var(--text-secondary);
        }

        .eula-box::-webkit-scrollbar {
            width: 8px;
        }

        .eula-box::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 4px;
        }

        .eula-box::-webkit-scrollbar-thumb {
            background: var(--border-secondary);
            border-radius: 4px;
        }

        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.875rem;
            cursor: pointer;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .checkbox-label input {
            margin-top: 0.25rem;
            width: 1.125rem;
            height: 1.125rem;
            accent-color: var(--primary);
        }

        .radio-label {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.875rem;
            cursor: pointer;
            padding: 1rem 1.25rem;
            border: 1.5px solid var(--border-primary);
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            background: var(--bg-secondary);
        }

        .radio-label:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-1px);
        }

        .radio-label input[type="radio"] {
            margin-top: 0.25rem;
            width: 1.125rem;
            height: 1.125rem;
            accent-color: var(--primary);
        }

        /* Requirements Grid */
        .requirements-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.8125rem;
        }

        .req-item {
            padding: 0.75rem 0.875rem;
            border-radius: 0.75rem;
            border: 1.5px solid var(--border-primary);
            background: var(--bg-secondary);
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.375rem;
            transition: all 0.2s ease;
        }

        .req-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .req-item.req-failed {
            border-color: var(--error-border);
            background: var(--error-bg);
        }

        .req-item.req-warn {
            border-color: var(--warning-border);
            background: var(--warning-bg);
        }

        .req-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .req-detail {
            width: 100%;
            color: var(--text-tertiary);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        @media (max-width: 900px) {
            .requirements-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 500px) {
            .requirements-grid {
                grid-template-columns: 1fr;
            }
        }

        .status-pass { color: var(--success); font-weight: 700; }
        .status-fail { color: var(--error); font-weight: 700; }
        .status-warn { color: var(--warning); font-weight: 700; }

        /* Install Tasks */
        .task-list {
            margin-bottom: 1rem;
        }

        .task-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-primary);
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            background: var(--bg-secondary);
        }

        .task-item:hover {
            background: var(--bg-tertiary);
        }

        .task-icon {
            width: 1.75rem;
            height: 1.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            color: var(--text-tertiary);
            background: var(--bg-tertiary);
            border-radius: 0.5rem;
        }

        .task-done .task-icon { 
            color: var(--success); 
            background: var(--success-bg);
        }
        .task-error .task-icon { 
            color: var(--error); 
            background: var(--error-bg);
        }
        .task-running .task-icon {
            background: var(--primary-light);
        }

        .task-label {
            font-size: 0.875rem;
            flex: 1;
            font-weight: 500;
        }

        .task-done .task-label { color: var(--success); }
        .task-error .task-label { color: var(--error); }

        .task-message {
            font-size: 0.75rem;
            color: var(--error);
            max-width: 50%;
            text-align: right;
            font-weight: 500;
        }

        .spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid var(--border-primary);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Cron */
        .cron-box {
            background: var(--bg-tertiary);
            border: 1.5px solid var(--border-primary);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .cron-box label {
            font-size: 0.875rem;
            font-weight: 600;
            display: block;
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
        }

        .code-block {
            background: var(--code-bg);
            color: var(--code-text);
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.8125rem;
            overflow-x: auto;
            margin-bottom: 0.875rem;
            word-break: break-all;
            box-shadow: var(--shadow-md);
        }

        .help-section details {
            border: 1.5px solid var(--border-primary);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
            background: var(--bg-secondary);
            overflow: hidden;
        }

        .help-section summary {
            padding: 1rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            background: var(--bg-tertiary);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .help-section summary:hover {
            background: var(--border-primary);
        }

        .help-section summary::after {
            content: '+';
            font-size: 1.25rem;
            font-weight: 300;
        }

        .help-section details[open] summary::after {
            content: '−';
        }

        .help-section ol {
            padding: 1.25rem 1.25rem 1.25rem 2.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .help-section ol li {
            margin-bottom: 0.625rem;
            line-height: 1.6;
        }

        .help-section code {
            background: var(--bg-tertiary);
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--primary);
        }

        /* Complete */
        .success-icon {
            width: 5rem;
            height: 5rem;
            background: var(--gradient-success);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 2rem;
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4);
            animation: successPop 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes successPop {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .success-title {
            text-align: center;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .success-message {
            text-align: center;
            color: var(--text-tertiary);
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        .info-box {
            background: var(--info-bg);
            border: 1.5px solid var(--info-border);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .info-box p {
            margin-bottom: 0.375rem;
            color: var(--text-secondary);
        }

        .info-box a {
            color: var(--info);
            font-weight: 600;
        }

        .step-description {
            color: var(--text-tertiary);
            font-size: 0.9375rem;
            margin-bottom: 1.5rem;
            line-height: 1.7;
        }

        .footer {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-tertiary);
            margin-top: 2rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .container {
                max-width: 100%;
            }

            .card {
                grid-template-columns: 260px 1fr;
            }

            .card-sidebar {
                padding: 2rem 1.75rem;
            }

            .card-body {
                padding: 2rem;
            }

            .card-body-header h1 {
                font-size: 1.125rem;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem 0.5rem;
            }

            .card {
                grid-template-columns: 1fr;
                border-radius: 1rem;
            }

            .card-sidebar {
                border-radius: 1rem 1rem 0 0;
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.75rem;
                padding: 1.25rem 1.5rem;
            }

            .card-sidebar .sidebar-icon {
                font-size: 1.75rem;
            }

            .card-sidebar h2 {
                font-size: 1.125rem;
            }

            .card-sidebar .sidebar-desc,
            .card-sidebar .sidebar-step {
                display: none;
            }

            .card-body-header h1 {
                font-size: 1rem;
            }

            .card-body {
                padding: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: auto;
            }

            .card-body-header .steps {
                gap: 0.25rem;
            }

            .step-dot {
                font-size: 0.6875rem;
                padding: 0.375rem 0.625rem;
            }

            .sub-steps {
                gap: 0.25rem;
            }

            .sub-step-dot {
                font-size: 0.625rem;
                padding: 0.25rem 0.5rem;
            }

            .task-message {
                max-width: 40%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Installation Wizard</h1>
        </div>
        {$this->renderInstallTimer($currentStep)}
        <div class="card">
            <div class="card-sidebar">
                <div class="sidebar-icon">{$sidebarIcon}</div>
                <h2>{$title}</h2>
                <p class="sidebar-desc">{$sidebarDesc}</p>
                <p class="sidebar-step">{$sidebarLabel}</p>
            </div>
            <div class="card-body">
                <div class="card-body-header">
                    <h1>Installation Wizard</h1>
                    {$stepIndicator}
                </div>
                {$subStepIndicator}
                {$content}
            </div>
        </div>
        <div class="footer">Installer v{$version}</div>
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
        <div class="install-timer" id="install-timer">
            <div class="timer-item">
                <span class="timer-label">Started:</span>
                <span class="timer-value" id="timer-start">--:--:--</span>
            </div>
            <div class="timer-item">
                <span class="timer-label">Elapsed:</span>
                <span class="timer-value" id="timer-elapsed">0s</span>
            </div>
            <div class="timer-item">
                <span class="timer-label">Remaining:</span>
                <span class="timer-value" id="timer-remaining">Calculating...</span>
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

        $html = '<div class="steps">';
        $visualNum = 0;
        foreach ($this->stepNames as $num => $name) {
            $visualNum++;
            $isSettingsGroup = ($num === 3);

            if ($isSettingsGroup) {
                // The "Settings" dot is active for internal steps 3-6, completed if past step 6
                if ($currentStep > 6) {
                    $class = 'completed';
                } elseif (in_array($currentStep, $this->settingsSubSteps)) {
                    $class = 'active';
                } else {
                    $class = '';
                }
            } else {
                if ($num < $currentStep) {
                    $class = 'completed';
                } elseif ($num === $currentStep) {
                    $class = 'active';
                } else {
                    $class = '';
                }
            }

            $html .= "<span class=\"step-dot {$class}\">{$visualNum}. {$name}</span>";
        }
        $html .= '</div>';

        return $html;
    }

    private function renderSubStepIndicator(int $currentStep): string
    {
        if (! in_array($currentStep, $this->settingsSubSteps)) {
            return '';
        }

        $html = '<div class="sub-steps">';
        foreach ($this->settingsSubSteps as $index => $step) {
            if ($step < $currentStep) {
                $class = 'completed';
            } elseif ($step === $currentStep) {
                $class = 'active';
            } else {
                $class = '';
            }
            $name = $this->settingsSubStepNames[$step];
            $num = $index + 1;
            $html .= "<span class=\"sub-step-dot {$class}\">{$num}. {$name}</span>";
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
