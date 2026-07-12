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
        return $this->appRoot().'/storage/app/updater';
    }

    private function backupsDir(): string
    {
        return $this->updaterStorageDir().'/backups';
    }

    private function resultsDir(): string
    {
        return $this->updaterStorageDir().'/results';
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

        return $this->updatesConfig = array_replace_recursive($defaults, $fromApp);
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
