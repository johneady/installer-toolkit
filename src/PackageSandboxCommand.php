<?php

namespace InstallerToolkit;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Extracts a built full package into a throwaway environment (temp dir +
 * disposable MySQL Docker container + PHP built-in server) and hands the
 * operator a URL to install.php, so the wizard can be driven manually in a
 * browser instead of scripted. Complements PackageTestCommand, which drives
 * the wizard automatically for CI/regression purposes.
 */
abstract class PackageSandboxCommand extends Command
{
    use LoadsPackageConfig;

    protected $signature = 'package:sandbox
        {--output=package : Output directory package:build wrote packages/ into}
        {--mysql-port=0 : Fixed host port for the throwaway MySQL container (0 = auto-pick a free port)}';

    protected $description = 'Extract the built package into a throwaway environment and serve it for a manual install.php run';

    protected string $slug;

    protected array $config;

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
        $this->mysqlContainerName = "package-sandbox-{$this->slug}-{$runId}";
        $this->tempDir = storage_path("app/package-sandbox-{$runId}");

        try {
            $zipPath = $this->locateFullZip();

            $mysql = $this->provisionMysql();

            $serverPort = $this->findFreePort();

            $this->extractOuterPackage($zipPath, $this->tempDir);

            $this->startPhpServer($this->tempDir, $serverPort);

            $this->printSummary($serverPort, $mysql);

            $this->waitUntilInterrupted();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('package:sandbox failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->teardown();
        }
    }

    protected function locateFullZip(): string
    {
        $version = config('app.version');
        $packagesDir = base_path($this->option('output')).'/packages';
        $zipPath = "{$packagesDir}/{$this->slug}-v{$version}-full.zip";

        if (! file_exists($zipPath)) {
            throw new RuntimeException("Expected built package not found: {$zipPath}. Run php artisan package:build first.");
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

        $port = ((int) $mysqlPortOption) ?: $this->findFreePort();
        $dbName = 'sandbox';
        $dbUser = 'sandbox';
        $dbPass = 'sandbox';

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

        // This command runs inside the host app's own Laravel process, whose
        // .env has already been loaded into the process environment. Symfony
        // Process inherits the parent environment by default, so without
        // clearing every key the host app's .env defines, the sandboxed
        // app's freshly-written .env would be silently shadowed by the host
        // app's own values once install.php runs — mirrors the same guard in
        // PackageTestCommand::startPhpServer().
        $blankEnv = array_fill_keys($this->parentAppEnvKeys(), false);

        $routerPath = $this->generateRouterScript($tempDir);

        $this->serverProcess = new Process(['php', '-S', "127.0.0.1:{$port}", '-t', $tempDir, $routerPath]);
        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start(null, $blankEnv);
    }

    /**
     * Every key defined in the host app's own .env file — used to blank out
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
     * router script replicates that rewrite for the life of the sandbox,
     * matching PackageTestCommand::generateRouterScript().
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

        $routerPath = $tempDir.'/.package-sandbox-router.php';
        file_put_contents($routerPath, $router);

        return $routerPath;
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
    protected function printSummary(int $serverPort, array $mysql): void
    {
        $this->newLine();
        $this->info('Sandbox ready. Open the installer in your browser:');
        $this->line("  http://127.0.0.1:{$serverPort}/install.php");
        $this->newLine();
        $this->info('Database details for step 3 of the wizard:');
        $this->line("  Host: {$mysql['host']}");
        $this->line("  Port: {$mysql['port']}");
        $this->line("  Database: {$mysql['name']}");
        $this->line("  User: {$mysql['user']}");
        $this->line("  Password: {$mysql['pass']}");
        $this->newLine();
        $this->line("  Extracted to: {$this->tempDir}");
        $this->newLine();
        $this->comment('Press Ctrl+C to tear down (stops the container, deletes the temp dir).');
    }

    protected function waitUntilInterrupted(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->warn('pcntl extension not available — press Enter to tear down instead of Ctrl+C.');
            fgets(STDIN);

            return;
        }

        $interrupted = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use (&$interrupted): void {
            $interrupted = true;
        });

        while (! $interrupted) {
            if (! $this->serverProcess?->isRunning()) {
                $this->warn('PHP built-in server stopped unexpectedly.');

                return;
            }

            usleep(200_000);
        }
    }

    protected function teardown(): void
    {
        $this->newLine();
        $this->info('Tearing down sandbox...');

        if ($this->serverProcess?->isRunning()) {
            $this->serverProcess->stop(3);
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
