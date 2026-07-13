<?php

trait HandlesRequests
{
    public function run(): void
    {
        applyHardenedSessionCookieParams();
        session_start();

        // Initialize session storage
        if (! isset($_SESSION['installer'])) {
            $_SESSION['installer'] = [];
        }

        $step = isset($_GET['step']) ? (int) $_GET['step'] : 0;
        $step = max(0, min($this->totalSteps, $step));

        // Already-installed guard — a fresh visitor (no installer progress
        // in their session) hitting a site that already has an APP_KEY sees
        // the "already installed" page. A session that reached step 7
        // (taskGenerateEnv() writes APP_KEY well before install_complete is
        // set at optimize_confirm) is mid-install, not a stranger arriving
        // at a finished site, so it must keep passing through — otherwise a
        // plain page reload during install/optimize traps the user on the
        // already-installed screen with no way back into their own install.
        // This must also keep passing through once install_complete flips
        // true: step 9 (renderComplete()) is itself the request that deletes
        // the app zip and install.php's self-delete script, so if this guard
        // excluded a completed session it would never reach that cleanup and
        // would show "already installed" instead — the zip cleanup
        // assertion in package:test caught exactly this. The 'admin' check
        // alone still bounds this to the install run itself: 'admin' can
        // outlive the session GC window on a completed install, and without
        // it a stale-but-alive session would keep bypassing the
        // already-installed screen on a live production site.
        //
        // This must run before the AJAX dispatch below: db-test and
        // mail-test open arbitrary attacker-supplied host:port connections
        // and echo back what they find, and were previously reachable by
        // anyone regardless of install progress — including on a finished
        // site where install.php was never deleted. Ajax handlers that
        // legitimately need to run without wizard progress (none currently
        // do) would need to be special-cased here.
        $inProgress = ! empty($_SESSION['installer']['admin']);
        if (! $inProgress && $this->isAlreadyInstalled()) {
            // restart-install is the one action this guard allows through:
            // a visitor whose session died mid-install (shared-host session
            // GC, browser crash, switching devices) would otherwise be stuck
            // on this screen with no way back in short of deleting files by
            // hand. It only fires when the app zip is still present — proof
            // the install never reached renderComplete(), which is the
            // request that deletes it — so a genuinely finished production
            // site can't be reset this way even if install.php was never
            // removed.
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart-install') {
                $this->requireValidCsrfToken();
                $this->handleInstallRestart();

                return;
            }

            $this->renderAlreadyInstalled();

            return;
        }

        // AJAX sub-actions are routed by name, not by which step they
        // happen to live on — a step reorder must not silently break a
        // test button or the install-task runner (see class docblock note
        // on why the render/validate/post switches below are still keyed
        // by step number: those genuinely are per-step).
        if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrfToken();
            $this->handleAjax($_GET['ajax']);

            return;
        }

        // Validate step access (can't skip ahead)
        $step = $this->validateStepAccess($step);

        // Handle POST submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrfToken();
            $this->handlePost($step);

            return;
        }

        // Render the current step
        $this->renderStep($step);
    }

    /**
     * Every state-changing POST (form submission or AJAX call) must carry
     * the per-session token minted by csrfToken() and rendered into forms/
     * window.CSRF_TOKEN by renderLayout() — otherwise a cross-site page
     * could ride the session cookie into e.g. handleAdminPost() or
     * handleInstallReset(). The plain GET-based step navigation this
     * installer otherwise uses is unaffected; only POST/AJAX go through
     * this check.
     */
    private function requireValidCsrfToken(): void
    {
        if (csrfTokenValid('csrf_token')) {
            return;
        }

        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your session has expired or this request could not be verified. Please reload the page and try again.']);
            exit;
        }

        $this->errors[] = 'Your session has expired or this request could not be verified. Please reload the page and try again.';
        $this->renderStep($this->validateStepAccess((int) ($_GET['step'] ?? 0)));
        exit;
    }

    // ========================================================================
    // AJAX Dispatch
    // ========================================================================

    /**
     * Routes AJAX sub-actions by name (?ajax=...) rather than by which step
     * number they happen to be requested from — see run()'s comment above
     * this dispatch for why that distinction matters.
     */
    private function handleAjax(string $action): void
    {
        // db-test and mail-test open a connection to attacker-supplied
        // host:port values and echo back what they find (banner text,
        // MySQL version) — a pre-auth network oracle if reachable by
        // anyone. Requiring that the wizard has already reached the
        // requirements step keeps them scoped to someone actually running
        // this install, not an anonymous visitor probing the network.
        $requiresProgress = in_array($action, ['db-test', 'mail-test'], true);
        if ($requiresProgress && empty($_SESSION['installer']['requirements_passed'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Installation has not progressed far enough to run this check.']);
            exit;
        }

        switch ($action) {
            case 'install-task':
                $this->handleInstallTask($_GET['task'] ?? '');
                break;
            case 'install-reset':
                $this->handleInstallReset();
                break;
            case 'db-test':
                $this->handleDatabaseTest();
                break;
            case 'mail-test':
                $this->handleMailTest();
                break;
            case 'mod-rewrite':
                $this->handleModRewriteCheck();
                break;
            default:
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown AJAX action.']);
                exit;
        }
    }

    /**
     * Reset installation task progress (retry from scratch) — clears the
     * completed-task list and any batch cursors so step 7 restarts every
     * task from the beginning.
     */
    private function handleInstallReset(): void
    {
        $_SESSION['installer']['completed_tasks'] = [];
        unset($_SESSION['installer']['extract_offset']);
        @unlink(__DIR__.'/.install-working.zip');
        @unlink(__DIR__.'/'.APP_FOLDER.'/public/install-optimize.php');
        echo json_encode(['success' => true]);
        exit;
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

        return (bool) preg_match('/^APP_KEY=base64:.+$/m', $envContent);
    }

    /**
     * Whether the install visible on disk is a stranded, never-finished
     * attempt rather than a genuinely completed production site — i.e. it's
     * safe to offer a restart. renderComplete() (step 9) is the one request
     * that deletes the app zip, so its continued presence is proof no
     * session ever reached that far, regardless of how long ago the
     * session that started this attempt died.
     */
    private function installNeverFinished(): bool
    {
        return file_exists(__DIR__.'/'.ZIP_FILENAME);
    }

    /**
     * Recovery path for a session lost mid-install (shared-host session GC,
     * browser crash, switching devices): wipes the session-scoped install
     * progress and any partial extraction/env artifacts, then restarts the
     * wizard from step 1. Gated on installNeverFinished() by the caller so
     * this can never touch a genuinely completed site.
     */
    private function handleInstallRestart(): void
    {
        session_regenerate_id(true);
        $_SESSION['installer'] = [];

        @unlink(__DIR__.'/.install-working.zip');
        @unlink(__DIR__.'/'.APP_FOLDER.'/public/install-optimize.php');
        @unlink(__DIR__.'/'.APP_FOLDER.'/.env');

        header('Location: install.php?step=1');
        exit;
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

    /**
     * Async counterpart to the requirements screen's mod_rewrite row: runs
     * the (potentially slow) self-HTTP rewrite probe in the background and
     * returns the rendered row, so the page can load instantly and fill the
     * result in once it resolves. See renderRequirements() / checkModRewrite().
     */
    private function handleModRewriteCheck(): void
    {
        header('Content-Type: application/json');

        $modRewrite = $this->checkModRewrite();

        echo json_encode([
            'html' => $this->renderRequirementRow([
                'name' => 'Apache mod_rewrite',
                'detail' => $modRewrite['passed']
                    ? $modRewrite['detail']
                    : $modRewrite['detail'].' If enabled: clean, SEO-friendly URLs without index.php in the path.',
                'passed' => $modRewrite['passed'],
                'critical' => false,
            ]),
        ]);
        exit;
    }

    /**
     * @return array{host: string, port: string, name: string, user: string, pass: string}
     */
    private function readDatabaseInput(): array
    {
        return [
            'host' => trim($_POST['db_host'] ?? ''),
            'port' => trim($_POST['db_port'] ?? '3306'),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => $_POST['db_pass'] ?? '',
        ];
    }

    /**
     * Open a PDO connection with the settings shared by the step-3 submit
     * and its "Test Connection" AJAX button, so both validate the database
     * identically instead of drifting apart.
     *
     * @param  array{host: string, port: string, name: string, user: string, pass: string}  $db
     */
    private function connectToDatabase(array $db): PDO
    {
        return new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']}",
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    }

    private function handleDatabasePost(): void
    {
        $db = $this->readDatabaseInput();

        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            // Persist the submitted values so the form repopulates instead of
            // blanking out — the suggested defaults only fill truly empty fields.
            $_SESSION['installer']['db'] = $db;
            $this->errors[] = 'Please fill in all required database fields.';
            $this->renderStep(3);

            return;
        }

        try {
            $this->connectToDatabase($db);
        } catch (PDOException $e) {
            $_SESSION['installer']['db'] = $db;
            $this->errors[] = 'Database connection failed: '.$e->getMessage();
            $this->renderStep(3);

            return;
        }

        $_SESSION['installer']['db'] = $db;

        header('Location: install.php?step=4');
        exit;
    }

    private function handleDatabaseTest(): void
    {
        header('Content-Type: application/json');

        $db = $this->readDatabaseInput();

        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
            exit;
        }

        try {
            $pdo = $this->connectToDatabase($db);
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
        } elseif (! filter_var($appUrl, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $appUrl)) {
            $this->errors[] = 'Application URL must be a valid http:// or https:// URL.';
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

        // A blank from-address is resolved at env-generation time (step 7),
        // where the admin email — collected on the *next* step — is known.
        // Resolving here could only ever see a stale value from a back-nav.

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
