<?php

trait HandlesRequests
{
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

}
