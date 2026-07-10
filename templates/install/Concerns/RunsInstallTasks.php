<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

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
                case 'bootstrap_files':
                    $this->taskBootstrapFiles();
                    break;
                case 'migrate':
                    $this->taskMigrate();
                    break;
                case 'migrate_batch':
                    $this->taskMigrateBatch();
                    break;
                case 'seed':
                    $this->taskSeed();
                    break;
                case 'seed_batch':
                    $this->taskSeedBatch();
                    break;
                case 'optimize':
                    $this->taskOptimize();
                    break;
                case 'optimize_confirm':
                    $this->taskOptimizeConfirm();
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Unknown task.']);
                    exit;
            }

            // Batched tasks (migrate/seed, and mid-batch extract) exit()
            // from inside their own batch loop before reaching this point,
            // so they mark themselves complete via markTaskComplete() only
            // once their final batch actually finishes — see
            // runMigrateBatch()/runSeedBatch()/taskExtract(). 'optimize'
            // likewise only reflects real completion once the browser has
            // confirmed every optimize command succeeded (optimize_confirm)
            // — see taskOptimize()'s docblock. Everything else that reaches
            // here (extract's final call, bootstrap_files) completed within
            // a single request and is recorded now.
            if ($task === 'optimize_confirm') {
                $this->markTaskComplete('optimize');
            } elseif (! in_array($task, ['optimize', 'migrate', 'migrate_batch', 'seed', 'seed_batch'], true)) {
                $this->markTaskComplete($task);
            }

            echo json_encode(['success' => true, 'message' => 'Completed.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $this->describeTaskFailure($task, $e->getMessage())]);
        }

        exit;
    }

    /**
     * Record a task as completed, guarding against duplicate entries since
     * some tasks (extract, migrate, seed) can reach this from more than
     * one call path across their batched requests.
     */
    private function markTaskComplete(string $task): void
    {
        $completed = $_SESSION['installer']['completed_tasks'] ?? [];
        if (! in_array($task, $completed, true)) {
            $completed[] = $task;
        }
        $_SESSION['installer']['completed_tasks'] = $completed;
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

    /**
     * Run the three fast, in-process bootstrap steps (.htaccess, .env,
     * storage symlink) in a single request instead of three separate
     * round trips — each of these is effectively instantaneous on its own.
     */
    private function taskBootstrapFiles(): void
    {
        $this->taskHtaccess();
        $this->taskGenerateEnv();
        $this->taskStorageLink();
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
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('Failed to create required directory: '.$dir.'. Check permissions on the '.APP_FOLDER.' folder.');
            }
            @chmod($dir, 0755);
            if (! $this->verifyWritableByTest($dir)) {
                throw new RuntimeException('Directory is not writable: '.$dir.'. Fix its permissions (0755, owned by the web server user) and retry.');
            }
        }

        // Clear cached bootstrap files — they may reference dev-only
        // packages (e.g. debugbar) that are not shipped in the zip.
        $cacheDir = $appDir.'/bootstrap/cache';
        foreach (['packages.php', 'services.php', 'config.php', 'routes-v7.php', 'blade-icons.php', 'events.php'] as $cacheFile) {
            @unlink($cacheDir.'/'.$cacheFile);
        }

        // Generate a fresh APP_KEY
        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $_SESSION['installer']['app_key'] = $appKey;

        $env = <<<ENV
APP_NAME="{$this->escapeEnvValue($settings['app_name'])}"
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL="{$this->escapeEnvValue($settings['app_url'])}"

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

        return require $bootstrapPath;
    }

    /**
     * Boot Laravel and run its kernel bootstrap (registering + booting all
     * service providers) exactly once, returning the booted app so callers
     * that need more than one Artisan command in the same request (e.g.
     * taskMigrate() wiping then enumerating migration paths) don't each pay
     * for a separate full framework boot.
     */
    private function bootLaravelKernel(): object
    {
        $app = $this->bootstrapLaravel();

        // Force the app to boot now, rather than lazily on Kernel::call().
        // Consuming apps commonly guard destructive Artisan commands with
        // DB::prohibitDestructiveCommands(app()->isProduction()) from their
        // own AppServiceProvider::boot(). .env is deliberately written with
        // APP_ENV=production for a real deployment, so that guard becomes
        // active as soon as providers boot. The installer legitimately needs
        // to run db:wipe/migrate once here, before any real data exists, so
        // lift the prohibition after boot but before any command runs.
        $app->make(Kernel::class)->bootstrap();
        DB::prohibitDestructiveCommands(false);

        return $app;
    }

    /**
     * Run an Artisan command safely, capturing output and preventing
     * Laravel's exception handler from rendering HTML error pages.
     */
    private function runArtisanCommand(string $command, array $params = [], ?object $app = null): string
    {
        $app ??= $this->bootLaravelKernel();
        $kernel = $app->make(Kernel::class);

        $output = new BufferedOutput;

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

    /**
     * Plain-English hints for the common failure classes an installer hits
     * on shared hosting (bad DB credentials, missing extensions,
     * permissions), keyed by the raw exception text patterns that indicate
     * each cause. A rule matches when the raw message contains every
     * pattern in its list (one pattern for a simple substring match; two
     * for an AND-style compound match like the "Class ... not found" rule).
     *
     * This is the single authored copy — ensureOptimizerEndpoint() exports
     * this same array into the generated install-optimize.php so the
     * standalone optimizer process (which has no access to this trait, see
     * its class docblock) shows identical hints instead of a hand-kept
     * second copy that can drift out of sync.
     *
     * @var list<array{patterns: list<string>, hint: string}>
     */
    private const FAILURE_HINTS = [
        ['patterns' => ['SQLSTATE[HY000] [2002]'], 'hint' => 'Could not reach the database server. Double-check the database host and port, and make sure the database server is running and accessible from this server.'],
        ['patterns' => ['Connection refused'], 'hint' => 'Could not reach the database server. Double-check the database host and port, and make sure the database server is running and accessible from this server.'],
        ['patterns' => ['SQLSTATE[HY000] [1045]'], 'hint' => 'The database username or password was rejected. Double-check the database credentials.'],
        ['patterns' => ['Access denied for user'], 'hint' => 'The database username or password was rejected. Double-check the database credentials.'],
        ['patterns' => ['SQLSTATE[HY000] [1049]'], 'hint' => 'The database name does not exist on the server. Create the database first, or check for a typo in the database name.'],
        ['patterns' => ['Unknown database'], 'hint' => 'The database name does not exist on the server. Create the database first, or check for a typo in the database name.'],
        ['patterns' => ['Permission denied'], 'hint' => 'The web server does not have permission to read or write a required file or directory. Check file ownership and permissions.'],
        ['patterns' => ['failed to open stream'], 'hint' => 'The web server does not have permission to read or write a required file or directory. Check file ownership and permissions.'],
        ['patterns' => ['Class', 'not found'], 'hint' => 'A required PHP class is missing, which usually means a Composer dependency did not get uploaded correctly. Try re-uploading the application package.'],
    ];

    /**
     * Prefix a raw task failure with its FAILURE_HINTS hint, if any
     * pattern matches. The raw detail is always kept below the hint so a
     * support ticket still has everything needed to diagnose anything this
     * doesn't recognize. Called once, from handleInstallTask()'s catch
     * block, so every task benefits regardless of which task method
     * actually threw.
     */
    private function describeTaskFailure(string $task, string $rawMessage): string
    {
        $hint = self::matchFailureHint($rawMessage, self::FAILURE_HINTS);

        return $hint !== null ? "{$hint} ({$rawMessage})" : $rawMessage;
    }

    /**
     * Shared matcher for FAILURE_HINTS, used by describeTaskFailure() here
     * and by the generated install-optimize.php's own copy of this method
     * (see ensureOptimizerEndpoint()) — kept as a small, static, dependency-
     * free function so it can be embedded verbatim in that standalone
     * script without duplicating any matching logic, only this one call.
     *
     * @param  list<array{patterns: list<string>, hint: string}>  $hints
     */
    private static function matchFailureHint(string $rawMessage, array $hints): ?string
    {
        foreach ($hints as $rule) {
            $allPatternsPresent = true;
            foreach ($rule['patterns'] as $pattern) {
                if (! str_contains($rawMessage, $pattern)) {
                    $allPatternsPresent = false;
                    break;
                }
            }
            if ($allPatternsPresent) {
                return $rule['hint'];
            }
        }

        return null;
    }

    /**
     * Migrations are the other slow step (alongside extraction) and, unlike
     * extraction, previously ran as a single opaque migrate:fresh call with
     * no progress feedback and no protection against gateway timeouts on
     * large schemas. This now mirrors taskExtract()/taskSeed(): drop all
     * tables once, then hand off to taskMigrateBatch() to run one migration
     * file per request, reporting progress as it goes.
     */
    private function taskMigrate(): void
    {
        // Boot Laravel once and reuse it for db:wipe, migrator path
        // enumeration, and the first migration below — each is a separate
        // Artisan call, but they don't each need their own full framework
        // boot (provider registration is the expensive part).
        $app = $this->bootLaravelKernel();

        $this->runArtisanCommand('db:wipe', ['--force' => true], $app);

        // Ask Laravel's own migrator for the full set of migration paths
        // rather than glob()'ing the app's database/migrations directory
        // directly. Packages (Filament plugins, Spatie packages, etc.)
        // commonly register their own migrations via loadMigrationsFrom()
        // in a service provider — those live outside database/migrations
        // and a plain glob() would silently skip them, exactly the set
        // migrate:fresh used to pick up automatically.
        $migrator = $app->make('migrator');
        $paths = array_merge($migrator->paths(), [__DIR__.'/'.APP_FOLDER.'/database/migrations']);
        $files = array_values($migrator->getMigrationFiles($paths));

        $_SESSION['installer']['migrate_files'] = $files;
        $_SESSION['installer']['migrate_index'] = 0;

        $this->runMigrateBatch($app);
    }

    private function taskMigrateBatch(): void
    {
        $this->runMigrateBatch();
    }

    /**
     * Upper bound on how many migration files to run per HTTP request.
     *
     * Booting the framework — registering and booting every service
     * provider — is by far the dominant cost of each request, measured at
     * roughly 5x the cost of actually running one migration. Because every
     * batched request is a fresh PHP process, bounding a request to only a
     * few files makes N migrations cost roughly N/size full framework boots
     * (which is why the wizard's migrate step is dramatically slower than a
     * one-shot `migrate:fresh`). Amortising one boot across a larger batch
     * collapses most of that overhead.
     *
     * This is only a cap: runMigrateBatch() also stops as soon as a request
     * has spent MAX_REQUEST_SECONDS running migrations, so a schema with a
     * few slow migrations can never exceed a shared host's gateway timeout
     * regardless of this value, while a fast schema packs many files into
     * each (fewer, cheaper) request.
     */
    private const MIGRATIONS_PER_REQUEST = 50;

    /**
     * Maximum wall-clock seconds a single migrate-batch request may spend
     * running migrations (measured across the framework boot + migration
     * loop, so the whole request stays within this budget). Keeps every
     * request well under the execution-time limit on even restrictive shared
     * hosts, while letting fast schemas pack as many migrations as fit into
     * the budget per request. Generous relative to typical 30s+ gateway
     * timeouts to leave headroom for a slow framework boot.
     */
    private const MAX_REQUEST_SECONDS = 15;

    /**
     * Run a batch of pending migration files per call, across multiple HTTP
     * requests, the same way runSeedBatch() steps through seeders. Each
     * request reuses a single booted framework instance for the whole batch
     * (see MIGRATIONS_PER_REQUEST) instead of paying one full framework
     * boot per migration file, and stops early once MAX_REQUEST_SECONDS
     * elapses so a slow schema can't blow a gateway timeout.
     */
    private function runMigrateBatch(?object $app = null): void
    {
        $files = $_SESSION['installer']['migrate_files'] ?? [];
        $index = $_SESSION['installer']['migrate_index'] ?? 0;
        $total = count($files);

        if ($index >= $total) {
            $this->markTaskComplete('migrate');
            echo json_encode([
                'success' => true,
                'migrate_done' => true,
                'message' => 'Completed.',
            ]);
            exit;
        }

        $end = min($index + self::MIGRATIONS_PER_REQUEST, $total);

        // Time the whole request from the start so the budget also covers
        // the framework boot below (the dominant per-request cost).
        $requestStart = microtime(true);

        // Reuse one booted framework instance for the entire batch rather
        // than letting runArtisanCommand() boot a fresh one per file: the
        // first call receives the instance taskMigrate() already booted,
        // and subsequent migrate_batch requests boot exactly once here.
        $app ??= $this->bootLaravelKernel();

        for ($i = $index; $i < $end; $i++) {
            // Stop the batch early once we've used the per-request time
            // budget, so a run of slow migrations can't exceed a shared
            // host's gateway/execution-time limit. The cursor has already
            // advanced past every file that actually ran, so the next
            // request resumes exactly here — already-run migrations are
            // no-ops, this just avoids re-evaluating them.
            if ($i > $index && microtime(true) - $requestStart > self::MAX_REQUEST_SECONDS) {
                break;
            }

            $file = $files[$i];

            // $file is an absolute path (it may live outside the app's own
            // database/migrations — e.g. inside vendor/ for a package that
            // registers its own migrations), so pass it through as-is with
            // --realpath rather than reconstructing a database/migrations-
            // relative path.
            $this->runArtisanCommand('migrate', [
                '--force' => true,
                '--path' => $file,
                '--realpath' => true,
            ], $app);

            // Advance the cursor after each successful file so a mid-batch
            // failure (or a time-budget break) resumes from the next file
            // on retry. Already-run migrations are no-ops (the migrator
            // skips anything present in the migrations repository), so this
            // is purely to avoid re-evaluating them.
            $_SESSION['installer']['migrate_index'] = $i + 1;
        }

        // Derive progress from the cursor rather than the planned $end: a
        // time-budget break may have processed fewer files than $end, and
        // the cursor is the source of truth for where to resume.
        $processedTo = $_SESSION['installer']['migrate_index'];
        $done = $processedTo >= $total;

        if ($done) {
            unset($_SESSION['installer']['migrate_files'], $_SESSION['installer']['migrate_index']);
            $this->markTaskComplete('migrate');
        }

        echo json_encode([
            'success' => true,
            'migrate_done' => $done,
            'message' => 'Migrated '.($index + 1)."-{$processedTo} of {$total}",
        ]);
        exit;
    }

    /**
     * Essential seeder classes that are always run. These set up core
     * configuration and other foundational data the application needs
     * to function (e.g. an admin user, default settings, navigation).
     *
     * Customize this list for your application's own seeders.
     *
     * @var list<string>
     */
    private const ESSENTIAL_SEED_CLASSES = [
        'Database\\Seeders\\AdminUserSeeder',
        'Database\\Seeders\\SettingSeeder',
    ];

    /**
     * Additional seeder classes that populate the site with demonstration
     * data, run only when the user opts in to sample data during install.
     *
     * Customize this list for your application's own seeders.
     *
     * @var list<string>
     */
    private const SAMPLE_SEED_CLASSES = [
        // 'Database\\Seeders\\DemoContentSeeder',
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
            $this->markTaskComplete('seed');
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

        if ($done) {
            $this->markTaskComplete('seed');
        }

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

        // Exported from the single authored copy (self::FAILURE_HINTS) so
        // the standalone optimizer process below shows identical failure
        // hints without a hand-kept second copy of the match logic.
        $failureHintsExport = var_export(self::FAILURE_HINTS, true);

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

\$commands = array_filter(explode(',', \$_GET['commands'] ?? ''));
\$allowed = ['config:clear', 'config:cache', 'event:cache', 'route:cache', 'view:cache', 'icons:cache', 'filament:optimize', 'package:discover'];

if (empty(\$commands) || array_diff(\$commands, \$allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid command.']);
    exit;
}

// Run one command per request — the same "one unit of work per HTTP
// request" batching used for migrate/seed — so a slow command (typically
// filament:optimize on an app with many resources) can't blow a shared
// host's gateway/execution-time budget for the whole optimize step, and a
// retry after a failure only re-runs from the command that actually
// failed rather than starting over from config:clear. This script has no
// session access (see the class docblock above), so the browser tracks
// and sends the index of the next command to run.
\$commands = array_values(\$commands);
\$index = (int) (\$_GET['index'] ?? 0);

if (\$index < 0 || \$index >= count(\$commands)) {
    echo json_encode(['success' => false, 'message' => 'Invalid command index.']);
    exit;
}

\$command = \$commands[\$index];

// This hint table and matching logic are exported verbatim from
// RunsInstallTasks::FAILURE_HINTS / matchFailureHint() at generation time
// (see ensureOptimizerEndpoint()) — there is exactly one authored copy of
// the rules; this standalone process (see the class docblock above) has no
// access to the installer class's methods, so it gets a data copy instead
// of a hand-kept second copy of the match logic.
\$failureHints = {$failureHintsExport};

function matchFailureHint(string \$rawMessage, array \$hints): ?string
{
    foreach (\$hints as \$rule) {
        \$allPatternsPresent = true;
        foreach (\$rule['patterns'] as \$pattern) {
            if (! str_contains(\$rawMessage, \$pattern)) {
                \$allPatternsPresent = false;
                break;
            }
        }
        if (\$allPatternsPresent) {
            return \$rule['hint'];
        }
    }

    return null;
}

function describeOptimizeFailure(string \$command, string \$rawMessage, ?int \$exitCode = null): string
{
    global \$failureHints;

    \$hint = matchFailureHint(\$rawMessage, \$failureHints);

    \$detail = \$exitCode !== null
        ? "{\$command} failed (exit code {\$exitCode}): ".substr(\$rawMessage, 0, 500)
        : "{\$command} failed: ".\$rawMessage;

    return \$hint !== null ? "{\$hint} ({\$detail})" : \$detail;
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
    echo json_encode(['success' => false, 'message' => describeOptimizeFailure(\$command, \$e->getMessage())]);
    exit;
}

ob_end_clean();

if (\$exitCode !== 0) {
    \$message = trim(\$output->fetch()) ?: 'Unknown error';
    echo json_encode(['success' => false, 'message' => describeOptimizeFailure(\$command, \$message, \$exitCode)]);
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

\$done = (\$index + 1) >= count(\$commands);

echo json_encode([
    'success' => true,
    'done' => \$done,
    'message' => \$done ? 'Completed.' : "Ran {\$command} (" . (\$index + 1) . '/' . count(\$commands) . ').',
]);
OPTIMIZER_PHP;

        if (file_put_contents($optimizerPath, $script) === false) {
            throw new RuntimeException('Failed to write install-optimize.php. Check directory permissions.');
        }
    }

    /**
     * Prepare the optimizer endpoint. The actual commands (config:clear,
     * package:discover, and the various *:cache steps) run one at a time
     * against install-optimize.php via the browser JS — the same "one
     * request per unit of work" batching used for migrate/seed — since
     * they share the "must run in a clean process" constraint.
     *
     * Deliberately does NOT mark installation complete here: that only
     * happens once the browser confirms every optimize command actually
     * succeeded (see taskOptimizeConfirm()). install-optimize.php runs as
     * its own standalone process with no session access, so it cannot flag
     * completion itself — the browser is the only thing that has seen the
     * result of every optimize request.
     */
    private function taskOptimize(): void
    {
        $this->ensureOptimizerEndpoint();
    }

    /**
     * Mark installation as complete. Called by the browser only after
     * install-optimize.php has reported success for every optimize
     * command — this is the final install task. If the browser never
     * confirms (tab closed, network drop), 'optimize' simply never lands
     * in completed_tasks and the whole task is retried from the top on the
     * next visit to step 7, same as any other interrupted task; every
     * optimize command is idempotent so re-running is safe.
     *
     * Trusts the browser's confirmation the same way every other install
     * step trusts the session holder — install-optimize.php is a
     * standalone, session-less script, so there's no stronger check
     * available here, and whoever holds this session can already do
     * anything the installer can do.
     */
    private function taskOptimizeConfirm(): void
    {
        $_SESSION['installer']['install_complete'] = true;
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
