<?php

namespace InstallerToolkit;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use InstallerToolkit\Concerns\ProvisionsSandboxEnvironment;
use RuntimeException;
use Throwable;

abstract class PackageTestCommand extends Command
{
    use LoadsPackageConfig;
    use ProvisionsSandboxEnvironment;

    protected $signature = 'package:test
        {--output=package : Output directory package:build wrote packages/ into}
        {--toolkit=~/php/installer-toolkit : Path to the installer-toolkit directory}
        {--keep : Leave the temp server dir and Docker container running for debugging}
        {--skip-build : Skip running package:build; reuse the most recent packages/*-full.zip}
        {--mysql-port=0 : Fixed host port for the throwaway MySQL container (0 = auto-pick a free port)}';

    protected $description = 'End-to-end test the install.php wizard against a freshly built package';

    /**
     * The exact task sequence install.php's step-7 JS drives, in order.
     * Must be kept in sync with templates/install/Concerns/RendersSteps.php's $tasks array.
     */
    protected const TASK_SEQUENCE = [
        'extract',
        'htaccess',
        'env',
        'migrate',
        'seed',
        'storage_link',
        'config_clear',
        'package_discover',
        'config_cache',
        'event_cache',
        'route_cache',
        'view_cache',
        'icons_cache',
        'filament_optimize',
    ];

    /**
     * Tasks that run via the standalone install-optimize.php endpoint,
     * mapped to the Artisan command name it invokes. Must be kept in sync
     * with templates/install/Concerns/RendersSteps.php's $cleanProcessTasks array.
     */
    protected const CLEAN_PROCESS_TASKS = [
        'config_clear' => 'config:clear',
        'package_discover' => 'package:discover',
        'config_cache' => 'config:cache',
        'event_cache' => 'event:cache',
        'route_cache' => 'route:cache',
        'view_cache' => 'view:cache',
        'icons_cache' => 'icons:cache',
        'filament_optimize' => 'filament:optimize',
    ];

    protected string $slug;

    protected array $config;

    protected string $testAdminEmail = 'test-admin@example.test';

    protected string $testAdminPassword = 'TestPassword123!';

    protected string $sampleDataMode = 'full';

    public function handle(): int
    {
        if ($configError = $this->loadPackageConfig()) {
            $this->error($configError);

            return self::FAILURE;
        }

        $runId = uniqid();
        $this->mysqlContainerName = "installer-toolkit-test-{$this->slug}-{$runId}";
        $this->tempDir = storage_path("app/package-test-{$runId}");

        try {
            $this->runBuild();

            $zipPath = $this->locateFullZip();

            $mysql = $this->provisionMysql();

            $serverPort = $this->findFreePort();
            $baseUri = "http://127.0.0.1:{$serverPort}";

            $this->extractOuterPackage($zipPath, $this->tempDir);

            $this->startPhpServer($this->tempDir, $serverPort);

            $client = Http::withOptions(['cookies' => new CookieJar, 'timeout' => 30])->baseUrl($baseUri);

            $this->waitForServer($client);

            $this->info('Running installer wizard...');
            $this->runWizard($client, $mysql, $baseUri);

            $this->info('Running functional checks...');
            $this->runFunctionalChecks($client, $baseUri);

            $this->newLine();
            $this->info('package:test passed — the install wizard produces a working application.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('package:test failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->teardown();
        }
    }

    protected function runBuild(): void
    {
        if ($this->option('skip-build')) {
            $this->info('Skipping package:build (--skip-build).');

            return;
        }

        $this->info('Building package via package:build...');
        $exitCode = $this->call('package:build', [
            '--output' => $this->option('output'),
            '--toolkit' => $this->option('toolkit'),
        ]);

        if ($exitCode !== self::SUCCESS) {
            throw new RuntimeException('package:build failed; aborting package:test.');
        }
    }

    protected function buildHint(): string
    {
        return 'Run package:build first, or omit --skip-build.';
    }

    protected function mysqlCredentials(): array
    {
        return ['installer_test', 'installer_test', 'installer_test'];
    }

    protected function routerFilename(): string
    {
        return '.package-test-router.php';
    }

    protected function waitForServer(PendingRequest $client): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            try {
                $client->get('/install.php');

                return;
            } catch (Throwable) {
                usleep(200_000);
            }
        }

        throw new RuntimeException('Timed out waiting for the PHP built-in server to respond.');
    }

    /**
     * @param  array{host: string, port: int, name: string, user: string, pass: string}  $mysql
     */
    protected function runWizard(PendingRequest $client, array $mysql, string $baseUri): void
    {
        $this->postStep($client, 1, ['accept_eula' => '1'], 2);

        $this->postStep($client, 2, [], 3);

        $this->postStep($client, 3, [
            'db_host' => $mysql['host'],
            'db_port' => (string) $mysql['port'],
            'db_name' => $mysql['name'],
            'db_user' => $mysql['user'],
            'db_pass' => $mysql['pass'],
        ], 4);

        $this->postStep($client, 4, [
            'app_name' => $this->config['name'],
            'app_url' => $baseUri,
            'timezone' => 'UTC',
            'sample_data' => $this->sampleDataMode,
        ], 5);

        $this->postStep($client, 5, [
            'mail_mailer' => 'log',
        ], 6);

        $this->postStep($client, 6, [
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => $this->testAdminEmail,
            'admin_password' => $this->testAdminPassword,
            'admin_password_confirm' => $this->testAdminPassword,
        ], 7);

        $this->runInstallTasks($client);

        $response = $client->get('/install.php?step=8');
        if ($response->status() !== 200) {
            throw new RuntimeException("Expected step 8 (cron) to return 200, got {$response->status()}.");
        }

        $response = $client->get('/install.php?step=9');
        if ($response->status() !== 200) {
            throw new RuntimeException("Expected step 9 (complete) to return 200, got {$response->status()}.");
        }

        $zipFilename = "{$this->slug}.zip";
        if (file_exists($this->tempDir.'/'.$zipFilename)) {
            throw new RuntimeException('install.php did not clean up the application zip after completion.');
        }

        if (file_exists($this->tempDir.'/'.$this->slug.'/public/install-optimize.php')) {
            throw new RuntimeException('install.php did not clean up install-optimize.php after completion.');
        }
    }

    protected function postStep(PendingRequest $client, int $step, array $data, int $expectedNextStep): void
    {
        $response = $client->withOptions(['allow_redirects' => false])
            ->asForm()
            ->post("/install.php?step={$step}", $data);

        $location = $response->header('Location');
        $expected = "install.php?step={$expectedNextStep}";

        if (! $response->redirect() || ! str_contains((string) $location, $expected)) {
            $detail = $this->describeStepFailure($client, $step);

            throw new RuntimeException("Step {$step} did not advance to step {$expectedNextStep} (got status {$response->status()}, Location: ".($location ?? 'none')."). {$detail}");
        }
    }

    protected function describeStepFailure(PendingRequest $client, int $step): string
    {
        if ($step === 2) {
            $body = $client->get("/install.php?step={$step}")->body();

            if (preg_match_all('/<h4[^>]*>([^<]+)<\/h4>\s*<p[^>]*>([^<]+)<\/p>\s*<span[^>]*>(Critical|Warning)<\/span>/', $body, $matches, PREG_SET_ORDER)) {
                $failing = array_filter($matches, fn ($m) => $m[3] === 'Critical');

                if (! empty($failing)) {
                    $names = implode(', ', array_map(fn ($m) => trim($m[1]), $failing));

                    return "Failing requirements: {$names}.";
                }
            }
        }

        return '';
    }

    protected function runInstallTasks(PendingRequest $client): void
    {
        $installPage = $client->get('/install.php?step=7')->body();

        if (! preg_match('/optimizeToken\s*=\s*[\'"]([a-f0-9]+)[\'"]/', $installPage, $matches)) {
            throw new RuntimeException('Could not find optimize_token on the step-7 install page.');
        }

        $optimizeToken = $matches[1];

        foreach (self::TASK_SEQUENCE as $task) {
            $this->runInstallTask($client, $task, $optimizeToken);

            if ($task === 'env') {
                $this->disableSecureSessionCookie();
            }
        }
    }

    /**
     * The wizard writes .env with APP_ENV=production, which defaults Laravel's
     * session cookie to Secure — correct for a real deployment, but this test
     * server is deliberately plain HTTP, and a Secure cookie is (correctly)
     * withheld by any spec-compliant HTTP client on non-TLS requests. Disable
     * it for the duration of this test run so the session actually persists.
     */
    protected function disableSecureSessionCookie(): void
    {
        $envPath = "{$this->tempDir}/{$this->slug}/.env";

        file_put_contents($envPath, "\nSESSION_SECURE_COOKIE=false\n", FILE_APPEND);
    }

    protected function runInstallTask(PendingRequest $client, string $task, string $optimizeToken): void
    {
        $maxIterations = 500;
        $originalTask = $task;
        $completed = false;

        for ($i = 0; $i < $maxIterations; $i++) {
            // Install tasks run real Artisan commands (migrate:fresh, db:seed,
            // etc.) server-side and can take much longer than the wizard's
            // other, near-instant steps — use a generous per-request timeout
            // here rather than raising it for the whole client. Apps with a
            // large migration count (200+) can take several minutes for
            // migrate:fresh alone.
            $response = $client->timeout(300)->post("/install.php?step=7&task={$task}");
            $json = $response->json();

            if ($json === null) {
                throw new RuntimeException("Task '{$task}' returned a non-JSON response (HTTP {$response->status()}): ".substr($response->body(), 0, 500));
            }

            if (! ($json['success'] ?? false)) {
                throw new RuntimeException("Task '{$task}' failed: ".($json['message'] ?? 'unknown error'));
            }

            if ($task === 'extract' && ($json['extract_done'] ?? true) === false) {
                continue;
            }

            if ($task === 'seed' && ($json['seed_done'] ?? true) === false) {
                $task = 'seed_batch';

                continue;
            }

            if ($task === 'seed_batch' && ($json['seed_done'] ?? true) === false) {
                continue;
            }

            $completed = true;
            break;
        }

        if (! $completed) {
            throw new RuntimeException("Task '{$originalTask}' did not complete after {$maxIterations} polling attempts.");
        }

        if (array_key_exists($originalTask, self::CLEAN_PROCESS_TASKS)) {
            $command = self::CLEAN_PROCESS_TASKS[$originalTask];

            $response = $client->get("/{$this->slug}/public/install-optimize.php?command=".urlencode($command).'&token='.urlencode($optimizeToken));
            $json = $response->json();

            if ($json === null || ! ($json['success'] ?? false)) {
                throw new RuntimeException("Optimize step '{$command}' failed: ".($json['message'] ?? "HTTP {$response->status()}"));
            }
        }
    }

    /**
     * Full functional check: verify .env was written correctly, log in as
     * the generated admin, and confirm an authenticated route responds.
     */
    protected function runFunctionalChecks(PendingRequest $client, string $baseUri): void
    {
        $envPath = "{$this->tempDir}/{$this->slug}/.env";

        if (! file_exists($envPath) || ! preg_match('/APP_KEY=base64:.+/', file_get_contents($envPath))) {
            throw new RuntimeException('.env was not written with a valid APP_KEY after installation.');
        }

        $loginPage = $client->get('/login')->body();

        if (! preg_match('/name="_token"\s+value="([^"]+)"/', $loginPage, $matches)
            && ! preg_match('/<meta name="csrf-token" content="([^"]+)"/', $loginPage, $matches)) {
            throw new RuntimeException('Could not find a CSRF token on the login page.');
        }

        $token = $matches[1];

        $response = $client->withOptions(['allow_redirects' => false])->asForm()->post('/login', [
            '_token' => $token,
            'email' => $this->testAdminEmail,
            'password' => $this->testAdminPassword,
        ]);

        if (! $response->redirect()) {
            throw new RuntimeException('Login failed for the generated admin account. Response: '.substr($response->body(), 0, 500));
        }

        $response = $client->get('/admin');

        if ($response->status() !== 200) {
            throw new RuntimeException("Expected /admin to return 200 after login, got {$response->status()}.");
        }

        $this->assertAppSpecificFunctionality($client, $baseUri);
    }

    /**
     * App-specific functional assertion hook. No-op by default; override in
     * the concrete subclass to hit a seeded/representative route.
     */
    protected function assertAppSpecificFunctionality(PendingRequest $client, string $baseUri): void
    {
        //
    }

    protected function teardown(): void
    {
        if ($this->serverProcess?->isRunning()) {
            $this->serverProcess->stop(3);
        }

        if ($this->option('keep')) {
            $this->warn("Kept temp install dir for debugging: {$this->tempDir}");
            $this->warn("MySQL container left running: {$this->mysqlContainerName} (docker stop {$this->mysqlContainerName} when done)");

            return;
        }

        $this->teardownSandboxEnvironment();
    }
}
