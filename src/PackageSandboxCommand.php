<?php

namespace InstallerToolkit;

use Illuminate\Console\Command;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use InstallerToolkit\Concerns\ProvisionsSandboxEnvironment;
use RuntimeException;
use Throwable;

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
    use ProvisionsSandboxEnvironment;

    protected $signature = 'package:sandbox
        {--output=package : Output directory package:build wrote packages/ into}
        {--keep : Leave the temp server dir and Docker container running for debugging}
        {--mysql-port=3306 : Fixed host port for the throwaway MySQL container (0 = auto-pick a free port)}';

    protected $description = 'Extract the built package into a throwaway environment and serve it for a manual install.php run';

    protected string $slug;

    protected array $config;

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

            $this->waitForServer($serverPort);

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

    protected function routerFilename(): string
    {
        return '.package-sandbox-router.php';
    }

    /**
     * Confirms the PHP built-in server has actually bound its port and is
     * accepting connections before handing the operator a URL — startPhpServer()
     * starts it via a non-blocking Process, so without this check a slow-to-bind
     * server could make the operator's first page load fail with a confusing
     * connection-refused error immediately after being told the sandbox is ready.
     */
    protected function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if (! $this->serverProcess?->isRunning()) {
                throw new RuntimeException('PHP built-in server failed to start: '.$this->serverErrorOutput());
            }

            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('Timed out waiting for the PHP built-in server to accept connections.');
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

        if (extension_loaded('pcntl')) {
            $this->comment('Press Ctrl+C to tear down (stops the container, deletes the temp dir).');
        } else {
            $this->comment('Press Enter to tear down (stops the container, deletes the temp dir).');
        }
    }

    protected function waitUntilInterrupted(): void
    {
        if (! extension_loaded('pcntl')) {
            fgets(STDIN);

            if (feof(STDIN)) {
                $this->warn('stdin is closed/non-interactive — tearing down immediately. Run this command from an interactive terminal, or install the pcntl extension to tear down with Ctrl+C instead.');
            }

            return;
        }

        $interrupted = false;
        pcntl_async_signals(true);
        $handler = function () use (&$interrupted): void {
            $interrupted = true;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);

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

        if ($this->option('keep')) {
            $this->warn("Kept temp sandbox dir for debugging: {$this->tempDir}");
            $this->warn("MySQL container left running: {$this->mysqlContainerName} (docker stop {$this->mysqlContainerName} when done)");

            if ($this->serverProcess?->isRunning()) {
                $this->serverProcess->stop(3);
            }

            return;
        }

        $this->info('Tearing down sandbox...');

        $this->teardownSandboxEnvironment();
    }
}
