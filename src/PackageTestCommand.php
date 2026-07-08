<?php

namespace InstallerToolkit;

use Dotenv\Dotenv;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

abstract class PackageTestCommand extends Command
{
    use LoadsPackageConfig;

    protected $signature = 'package:test
        {--output=package : Output directory package:build wrote packages/ into}
        {--toolkit=~/php/installer-toolkit : Path to the installer-toolkit directory}
        {--keep : Leave the temp server dir and Docker container running for debugging}
        {--skip-build : Skip running package:build; reuse the most recent packages/*-full.zip}
        {--mysql-port=0 : Fixed host port for the throwaway MySQL container (0 = auto-pick a free port)}';

    protected $description = 'End-to-end test the install.php wizard against a freshly built package';

    /**
     * The exact task sequence install.php's step-7 JS drives, in order.
     * Must be kept in sync with templates/install.php's $tasks array.
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
     * with templates/install.php's $cleanProcessTasks array.
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

    protected ?Process $serverProcess = null;

    protected ?string $mysqlContainerName = null;

    protected ?string $tempDir = null;

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

    protected function locateFullZip(): string
    {
        $version = config('app.version');
        $packagesDir = base_path($this->option('output')).'/packages';
        $zipPath = "{$packagesDir}/{$this->slug}-v{$version}-full.zip";

        if (! file_exists($zipPath)) {
            throw new RuntimeException("Expected built package not found: {$zipPath}. Run package:build first, or omit --skip-build.");
        }

        return $zipPath;
    }

    /**
     * @return array{host: string, port: int, name: string, user: string, pass: string}
     */
    protected function provisionMysql(): array
    {
        $mysqlPortOption = $this->option('mysql-port');

        if (! ctype_digit((string) $mysqlPortOption)) {
            throw new RuntimeException("--mysql-port must be a non-negative integer, got: '{$mysqlPortOption}'.");
        }

        // findFreePort() releases its probe socket before returning, so there
        // is an inherent (small) race if another process grabs the port
        // first — acceptable for a local/CI test harness; Docker will fail
        // fast and loudly via the isSuccessful() check below if that happens.
        $port = ((int) $mysqlPortOption) ?: $this->findFreePort();
        $dbName = 'installer_test';
        $dbUser = 'installer_test';
        $dbPass = 'installer_test';

        $this->info("Starting throwaway MySQL container on port {$port}...");

        $process = new Process([
            'docker', 'run', '-d', '--rm',
            '--name', $this->mysqlContainerName,
            '-e', 'MYSQL_ROOT_PASSWORD=root',
            '-e', "MYSQL_DATABASE={$dbName}",
            '-e', "MYSQL_USER={$dbUser}",
            '-e', "MYSQL_PASSWORD={$dbPass}",
            '-p', "{$port}:3306",
            'mysql:8.0',
            '--default-authentication-plugin=mysql_native_password',
        ]);
        // Generous timeout to accommodate a cold `docker pull` of the mysql
        // image on a machine/CI runner that doesn't have it cached yet.
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Failed to start MySQL container: '.$process->getErrorOutput());
        }

        $mysql = ['host' => '127.0.0.1', 'port' => $port, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];

        $this->waitForMysql($mysql);

        return $mysql;
    }

    /**
     * @param  array{host: string, port: int, name: string, user: string, pass: string}  $mysql
     */
    protected function waitForMysql(array $mysql): void
    {
        $this->info('Waiting for MySQL to accept connections...');

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            try {
                new PDO(
                    "mysql:host={$mysql['host']};port={$mysql['port']};dbname={$mysql['name']}",
                    $mysql['user'],
                    $mysql['pass'],
                    [PDO::ATTR_TIMEOUT => 2]
                );

                $this->info('MySQL is ready.');

                return;
            } catch (PDOException) {
                usleep(500_000);
            }
        }

        throw new RuntimeException("Timed out waiting for MySQL container {$this->mysqlContainerName} to accept connections on port {$mysql['port']}.");
    }

    protected function extractOuterPackage(string $zipPath, string $tempDir): void
    {
        $this->info('Extracting built package...');

        File::ensureDirectoryExists($tempDir);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Failed to open built package zip: {$zipPath}");
        }

        $zip->extractTo($tempDir);
        $zip->close();

        if (! file_exists($tempDir.'/install.php')) {
            throw new RuntimeException('install.php not found after extracting the built package.');
        }
    }

    protected function startPhpServer(string $tempDir, int $port): void
    {
        $this->info("Starting PHP built-in server on 127.0.0.1:{$port}...");

        // package:test runs inside this very app's own Laravel process, whose
        // .env has already been loaded into the process environment by
        // Dotenv (via putenv()/$_SERVER/$_ENV). Symfony Process inherits the
        // parent environment by default, so without clearing every key this
        // dev app's own .env defines, the installed app's freshly-written
        // .env would be silently shadowed by this dev environment's own
        // values (Dotenv's immutable repository refuses to overwrite
        // already-set env vars) — the same class of bug bootstrapLaravel()
        // in install.php guards against for session/cache/queue.
        $blankEnv = array_fill_keys($this->parentAppEnvKeys(), false);

        $routerPath = $this->generateRouterScript($tempDir);

        $this->serverProcess = new Process(['php', '-S', "127.0.0.1:{$port}", '-t', $tempDir, $routerPath]);
        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start(null, $blankEnv);
    }

    /**
     * Every key defined in this dev app's own .env file — used to blank out
     * the child php -S process's environment so none of it can shadow the
     * freshly-installed app's own .env values.
     *
     * @return array<string>
     */
    protected function parentAppEnvKeys(): array
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return [];
        }

        return array_keys(Dotenv::parse(file_get_contents($envPath)));
    }

    /**
     * PHP's built-in server has no equivalent of the .htaccess rewrite
     * install.php writes for Apache, so requests other than install.php
     * itself would 404 instead of reaching {slug}/public/index.php. This
     * router script replicates that rewrite for the duration of the test.
     */
    protected function generateRouterScript(string $tempDir): string
    {
        $slug = $this->slug;

        $router = <<<PHP
<?php
\$uri = urldecode(parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (\$uri === '/install.php' || \$uri === '/_cleanup.php') {
    return false;
}

if (str_starts_with(\$uri, '/{$slug}/public/')) {
    return false;
}

\$publicPath = __DIR__ . '/{$slug}/public' . \$uri;
if (\$uri !== '/' && is_file(\$publicPath)) {
    return false;
}

\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/{$slug}/public/index.php';
chdir(__DIR__ . '/{$slug}/public');
require __DIR__ . '/{$slug}/public/index.php';
PHP;

        $routerPath = $tempDir.'/.package-test-router.php';
        file_put_contents($routerPath, $router);

        return $routerPath;
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

    protected function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Failed to find a free port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
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
            // here rather than raising it for the whole client.
            $response = $client->timeout(120)->post("/install.php?step=7&task={$task}");
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

        if ($this->mysqlContainerName) {
            $stopProcess = new Process(['docker', 'stop', $this->mysqlContainerName]);
            $stopProcess->setTimeout(30);
            $stopProcess->run();
        }

        if ($this->tempDir && is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    }
}
