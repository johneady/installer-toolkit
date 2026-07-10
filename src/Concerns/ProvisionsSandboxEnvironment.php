<?php

namespace InstallerToolkit\Concerns;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Shared throwaway-environment plumbing for package:test and package:sandbox:
 * locating the built zip, provisioning a disposable MySQL Docker container,
 * extracting the package, and serving it via PHP's built-in server behind a
 * router script that replicates install.php's .htaccess rewrite.
 */
trait ProvisionsSandboxEnvironment
{
    protected ?Process $serverProcess = null;

    protected ?string $mysqlContainerName = null;

    protected ?string $tempDir = null;

    protected function locateFullZip(): string
    {
        $version = config('app.version');
        $zipPath = "{$this->packagesDir()}/{$this->slug}-v{$version}-full.zip";

        if (! file_exists($zipPath)) {
            throw new RuntimeException("Expected built package not found: {$zipPath}. {$this->buildHint()}");
        }

        return $zipPath;
    }

    /**
     * Directory the built zips are looked up in. Override in the concrete
     * command to point elsewhere (package:test redirects a fresh build into
     * an isolated temp dir). Relies on resolveOutputDir() from
     * LoadsPackageConfig, which every command using this trait also uses.
     */
    protected function packagesDir(): string
    {
        return $this->resolveOutputDir($this->option('output')).'/packages';
    }

    /**
     * Hint appended to locateFullZip()'s error message, describing how to
     * produce the missing zip. Override in the concrete command.
     */
    protected function buildHint(): string
    {
        return 'Run php artisan package:build first.';
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
        [$dbName, $dbUser, $dbPass] = $this->mysqlCredentials();

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
     * Database name/user/password to provision the throwaway MySQL container
     * with. Override in the concrete command to avoid collisions between
     * commands run concurrently against the same Docker daemon.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function mysqlCredentials(): array
    {
        return ['sandbox', 'sandbox', 'sandbox'];
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
        // app's own values once install.php runs — the same class of bug
        // bootstrapLaravel() in install.php guards against for
        // session/cache/queue.
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
     * router script replicates that rewrite for the life of the sandbox.
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
    \$extensionMimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain',
        'webmanifest' => 'application/manifest+json',
    ];
    \$extension = strtolower(pathinfo(\$publicPath, PATHINFO_EXTENSION));
    \$mimeType = \$extensionMimeTypes[\$extension] ?? (mime_content_type(\$publicPath) ?: 'application/octet-stream');
    header('Content-Type: ' . \$mimeType);
    readfile(\$publicPath);

    return true;
}

\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/{$slug}/public/index.php';
chdir(__DIR__ . '/{$slug}/public');
require __DIR__ . '/{$slug}/public/index.php';
PHP;

        $routerPath = $tempDir.'/'.$this->routerFilename();
        file_put_contents($routerPath, $router);

        return $routerPath;
    }

    /**
     * Dotfile name for the generated router script. Override in the concrete
     * command so multiple sandbox/test commands can be told apart on disk.
     */
    protected function routerFilename(): string
    {
        return '.sandbox-router.php';
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
     * Stops the PHP server and MySQL container and deletes the temp dir.
     * Concrete commands call this from their own teardown() so they can
     * layer command-specific behavior (e.g. a --keep debug flag) around it.
     */
    protected function teardownSandboxEnvironment(): void
    {
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
