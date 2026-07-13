<?php

/**
 * Resolves everything the updater needs to know about the application it
 * lives inside — paths, the parsed .env, the resolved config/updates.php,
 * and the currently installed version — all without booting the framework.
 */
trait BootsUpdaterEnvironment
{
    private function appRoot(): string
    {
        return UPDATER_APP_ROOT;
    }

    private function updaterStorageDir(): string
    {
        // Same value the Laravel package reads (config/updates.php
        // updater.storage_dir), so the handoff token and results are always
        // written and read from the one location both sides agree on.
        $dir = (string) ($this->updatesConfig()['updater']['storage_dir'] ?? 'storage/app/updater');

        return $this->appRoot().'/'.ltrim($dir, '/');
    }

    private function backupsDir(): string
    {
        return $this->updaterStorageDir().'/backups';
    }

    private function resultsDir(): string
    {
        return $this->updaterStorageDir().'/results';
    }

    /**
     * Marker recording that an update run is underway (or was interrupted
     * partway through). Written by taskPrepare() with the target version,
     * removed by taskFinalize() and a completed restore. Its presence on
     * disk — unlike anything session-bound — survives a lost browser
     * session, which is exactly the scenario it exists for: extraction may
     * have already bumped config/app.php's version literal before the run
     * died, and this marker is what lets ValidatesUpdatePackage accept the
     * same-version package again to finish the job.
     */
    private function updateInProgressFile(): string
    {
        return $this->updaterStorageDir().'/update-in-progress.json';
    }

    private function writeUpdateInProgressMarker(?string $versionTo): void
    {
        @file_put_contents($this->updateInProgressFile(), json_encode([
            'version_to' => $versionTo,
            'started_at' => date('c'),
        ]));
    }

    private function clearUpdateInProgressMarker(): void
    {
        @unlink($this->updateInProgressFile());
    }

    /**
     * The one authored copy of the update's disk-space heuristic: the
     * uploaded package, its staged inner zip, the pre-update backup, and
     * the extracted files all coexist on disk during a run — roughly 4x the
     * package size. Returns [needed, free] when free space is definitely
     * insufficient, or null when there is enough (or free space cannot be
     * determined, which must not block an update). Shared by the upload
     * gate, the review screen, and the start-update guard so the three can
     * never disagree about whether a run fits.
     *
     * @return array{needed: int, free: int}|null
     */
    private function diskSpaceShortfall(int $packageBytes, string $dir): ?array
    {
        $needed = $packageBytes * 4;
        $free = function_exists('disk_free_space') ? @disk_free_space($dir) : false;

        if ($free === false || $free >= $needed) {
            return null;
        }

        return ['needed' => $needed, 'free' => (int) $free];
    }

    /**
     * The version_to of an interrupted update run, or null when no run is
     * in progress/interrupted.
     */
    private function interruptedUpdateVersion(): ?string
    {
        $file = $this->updateInProgressFile();

        if (! is_file($file)) {
            return null;
        }

        $marker = json_decode((string) file_get_contents($file), true);

        return is_array($marker) && is_string($marker['version_to'] ?? null)
            ? $marker['version_to']
            : null;
    }

    private function ensureUpdaterDirs(): void
    {
        foreach ([$this->updaterStorageDir(), $this->backupsDir(), $this->resultsDir()] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Cannot create {$dir}. Check that storage/app is writable by the web server.");
            }
        }
    }

    /**
     * Parse the application's .env into the global store env() reads from.
     * Deliberately simple (KEY=VALUE, quoted values, # comment lines) —
     * matching the subset the installer itself writes. Values already in
     * the real environment are never overridden, mirroring Dotenv.
     */
    private function loadEnvIntoStore(): void
    {
        $envPath = $this->appRoot().'/.env';

        if (! is_file($envPath) || ! is_readable($envPath)) {
            return;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Strip a matching pair of surrounding quotes and unescape the
            // sequences the installer's escapeEnvValue() produces.
            if (strlen($value) > 1) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
                    }
                }
            }

            $GLOBALS['__updater_env'][$key] = $value;
        }
    }

    /**
     * The application's resolved config/updates.php merged over safe
     * defaults, so a missing or partially-broken config file degrades to
     * the same defaults the Laravel package ships with.
     *
     * @return array<string, mixed>
     */
    private function updatesConfig(): array
    {
        if ($this->updatesConfig !== null) {
            return $this->updatesConfig;
        }

        $defaults = [
            'slug' => env('UPDATE_SLUG', APP_FOLDER),
            'updater' => ['storage_dir' => 'storage/app/updater'],
            'protected_paths' => [
                '.env',
                'storage/app/license.key',
                'storage/app/public/',
                'storage/logs/',
                'storage/framework/sessions/',
            ],
            'signing' => ['trusted_keys' => []],
            'backup' => [
                'enabled' => true,
                'keep' => 3,
                'include_vendor' => true,
                'exclude' => [
                    'node_modules',
                    '.git',
                    'storage/framework',
                    'storage/logs',
                    'package',
                    'tests',
                ],
                'database' => [
                    'enabled' => true,
                    'mysqldump_path' => 'mysqldump',
                ],
            ],
        ];

        $configPath = $this->appRoot().'/config/updates.php';
        $fromApp = [];

        if (is_file($configPath) && is_readable($configPath)) {
            try {
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $fromApp = $loaded;
                }
            } catch (Throwable) {
                // A config file the updater can't parse must not block a
                // recovery-mode update — fall back to defaults.
            }
        }

        return $this->updatesConfig = $this->mergeConfigOverDefaults($defaults, $fromApp);
    }

    /**
     * Deep-merge $overrides over $defaults, but replace list-type values
     * (sequential, numeric-keyed arrays like `protected_paths` or
     * `backup.exclude`) wholesale rather than index-by-index.
     * array_replace_recursive merges lists positionally, so an app config
     * with e.g. 3 protected_paths entries would still inherit index 3+ from
     * the 5-entry default list — an operator can never actually shrink or
     * fully replace a default list, only overwrite its first N entries.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeConfigOverDefaults(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($value)) {
                $defaults[$key] = $this->mergeConfigOverDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private function slug(): string
    {
        $slug = (string) ($this->updatesConfig()['slug'] ?? APP_FOLDER);

        return $slug !== '' ? $slug : APP_FOLDER;
    }

    private function innerZipName(): string
    {
        return $this->slug().'.zip';
    }

    /**
     * @return list<string>
     */
    private function protectedPaths(): array
    {
        return array_values(array_map('strval', (array) ($this->updatesConfig()['protected_paths'] ?? [])));
    }

    /**
     * @return array<string, string>
     */
    private function trustedKeys(): array
    {
        return array_map('strval', (array) ($this->updatesConfig()['signing']['trusted_keys'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function backupConfig(): array
    {
        return (array) ($this->updatesConfig()['backup'] ?? []);
    }

    /**
     * The currently installed version, parsed from config/app.php's version
     * literal. The updater must not boot the app to ask config('app.version')
     * — parsing the same literal UpdateService::verify() checks keeps the two
     * in agreement.
     */
    private function currentVersion(): ?string
    {
        $configPath = $this->appRoot().'/config/app.php';

        if (! is_file($configPath) || ! is_readable($configPath)) {
            return null;
        }

        $content = (string) file_get_contents($configPath);

        if (preg_match('/[\'"]version[\'"]\s*=>\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]/', $content, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Database connection settings from the parsed .env, for backup/restore.
     *
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}
     */
    private function databaseSettings(): array
    {
        return [
            'connection' => (string) env('DB_CONNECTION', 'mysql'),
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '3306'),
            'database' => (string) env('DB_DATABASE', ''),
            'username' => (string) env('DB_USERNAME', ''),
            'password' => (string) env('DB_PASSWORD', ''),
        ];
    }

    /**
     * Laravel's maintenance-mode marker. Writing/removing the file directly
     * is what `php artisan down`/`up` do under the hood — no framework boot
     * needed, and it works even when the app can't boot at all.
     */
    private function maintenanceFile(): string
    {
        return $this->appRoot().'/storage/framework/down';
    }

    private function enableMaintenanceMode(): void
    {
        $dir = dirname($this->maintenanceFile());
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = json_encode([
            'except' => [],
            'retry' => 60,
            'refresh' => 15,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ]);

        if (@file_put_contents($this->maintenanceFile(), $payload) === false) {
            throw new RuntimeException('Failed to enable maintenance mode. Check that storage/framework is writable.');
        }
    }

    private function disableMaintenanceMode(): void
    {
        if (file_exists($this->maintenanceFile())) {
            @unlink($this->maintenanceFile());
        }
    }
}
