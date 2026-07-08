<?php

trait RunsInstallTasks
{
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

        // Force the app to boot (registering + booting all service providers)
        // now, rather than lazily on Kernel::call(). Consuming apps commonly
        // guard destructive Artisan commands with
        // DB::prohibitDestructiveCommands(app()->isProduction()) from their
        // own AppServiceProvider::boot(). .env is deliberately written with
        // APP_ENV=production for a real deployment, so that guard becomes
        // active as soon as providers boot. The installer legitimately needs
        // to run migrate:fresh once here, before any real data exists, so
        // lift the prohibition after boot but before the command runs.
        $kernel->bootstrap();
        Illuminate\Support\Facades\DB::prohibitDestructiveCommands(false);

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

}
