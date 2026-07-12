<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Snapshots application files and the database before an update so that a
 * failed (or unwanted) update can be rolled back.
 */
class UpdateBackupManager
{
    /**
     * Create a backup and return its identifier.
     *
     * @return string The backup id (used as the directory name).
     */
    public function create(): string
    {
        $id = date('YmdHis').'-'.bin2hex(random_bytes(4));
        $dir = $this->directory($id);
        File::ensureDirectoryExists($dir);

        $this->backupFiles($dir.'/files.zip');
        $this->backupDatabase($dir.'/database.sql');

        file_put_contents($dir.'/backup.json', json_encode([
            'id' => $id,
            'version' => (string) config('app.version'),
            'created_at' => now()->toIso8601String(),
            'app_path' => base_path(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    /**
     * Restore a previously created backup over the live application.
     *
     * Files that exist in the app but not in the backup (i.e. files a
     * failed update added) are deleted first, so the restore reproduces the
     * pre-update state instead of leaving orphans behind — new migration
     * files in particular must not survive a rollback whose database was
     * restored to the old schema. Deletion is scoped to what the backup
     * actually covers: excluded segments and protected paths are never
     * touched.
     */
    public function restore(string $backupId, bool $skipDatabase = false): void
    {
        $dir = $this->directory($backupId);

        if (! is_dir($dir)) {
            throw new RuntimeException("Backup [{$backupId}] does not exist.");
        }

        $filesZip = $dir.'/files.zip';

        if (file_exists($filesZip)) {
            $zip = new ZipArchive;

            if ($zip->open($filesZip) === true) {
                $this->deleteFilesAbsentFromBackup($zip);
                $zip->extractTo(base_path());
                $zip->close();
            }
        }

        if (! $skipDatabase) {
            $this->restoreDatabase($dir.'/database.sql');
        }
    }

    /**
     * Delete files present in the app tree but absent from the backup zip.
     * Only paths the backup covers are candidates — anything matching the
     * backup exclusions (never archived) or the update-protected paths
     * (user data such as uploads, license key, .env) is left alone.
     */
    protected function deleteFilesAbsentFromBackup(ZipArchive $zip): void
    {
        $inBackup = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $inBackup[str_replace('\\', '/', (string) $zip->getNameIndex($i))] = true;
        }

        $base = base_path();
        $exclude = $this->excludedSegments();

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relative = ltrim(str_replace($base, '', (string) $file->getPathname()), DIRECTORY_SEPARATOR);
            $normalized = str_replace('\\', '/', $relative);

            if ($this->isExcluded($relative, $exclude) || $this->isNeverDeleted($normalized)) {
                continue;
            }

            if (! isset($inBackup[$normalized])) {
                @unlink((string) $file->getPathname());
            }
        }
    }

    /**
     * Paths a restore must never delete even when missing from the backup:
     * the update-protected paths (uploads, license key, .env) plus SQLite
     * data files, mirroring UpdateService::isProtectedPath().
     */
    protected function isNeverDeleted(string $relativePath): bool
    {
        foreach ((array) config('updates.protected_paths', []) as $protected) {
            $protected = (string) $protected;

            if (str_ends_with($protected, '/') && str_starts_with($relativePath, $protected)) {
                return true;
            }

            if ($relativePath === $protected) {
                return true;
            }
        }

        return str_starts_with($relativePath, 'database/') && str_ends_with($relativePath, '.sqlite');
    }

    /**
     * Keep only the most recent N backups, deleting the rest.
     *
     * @return int Number of backups pruned.
     */
    public function prune(int $keep): int
    {
        if ($keep < 1) {
            $keep = 1;
        }

        $root = $this->rootDirectory();

        if (! is_dir($root)) {
            return 0;
        }

        // Sort by value rather than keying the map by mtime — two backups
        // created in the same second would collide as keys and one would
        // silently escape retention accounting.
        $backups = collect(glob($root.'/*', GLOB_ONLYDIR) ?: [])
            ->sortByDesc(fn (string $path) => filemtime($path));

        $pruned = 0;

        foreach ($backups->skip($keep) as $path) {
            File::deleteDirectory((string) $path);
            $pruned++;
        }

        return $pruned;
    }

    public function directory(string $backupId): string
    {
        return $this->rootDirectory().'/'.preg_replace('/[^a-zA-Z0-9\-_]/', '', $backupId);
    }

    public function rootDirectory(): string
    {
        return storage_path('app/'.(string) config('updates.backup.directory', 'update-backups'));
    }

    /**
     * List all backups on disk, newest first.
     *
     * Each entry includes metadata from the backup's manifest (when
     * available) plus whether a database dump exists.
     *
     * @return list<array{id: string, version: ?string, created_at: ?string, has_database: bool, size: int}>
     */
    public function listBackups(): array
    {
        $root = $this->rootDirectory();

        if (! is_dir($root)) {
            return [];
        }

        $backups = [];

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename((string) $dir);

            $entry = [
                'id' => $id,
                'version' => null,
                'created_at' => null,
                'has_database' => false,
                'size' => 0,
            ];

            $manifestPath = $dir.'/backup.json';

            if (file_exists($manifestPath)) {
                $data = json_decode((string) file_get_contents($manifestPath), true);

                if (is_array($data)) {
                    $entry['version'] = $data['version'] ?? null;
                    $entry['created_at'] = $data['created_at'] ?? null;
                }
            }

            $entry['has_database'] = file_exists($dir.'/database.sql');
            $entry['size'] = $this->directorySize((string) $dir);

            $backups[] = $entry;
        }

        usort($backups, fn (array $a, array $b): int => strcmp($b['id'], $a['id']));

        return $backups;
    }

    /**
     * Check whether a backup with the given id exists on disk.
     */
    public function exists(string $backupId): bool
    {
        return is_dir($this->directory($backupId));
    }

    /**
     * Recursively calculate the total size of a directory in bytes.
     */
    protected function directorySize(string $dir): int
    {
        $size = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        ) as $file) {
            $size += (int) $file->getSize();
        }

        return $size;
    }

    protected function backupFiles(string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup archive.');
        }

        $base = base_path();
        $exclude = $this->excludedSegments();

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $file) {
            $relative = ltrim(str_replace($base, '', (string) $file->getPathname()), DIRECTORY_SEPARATOR);

            if ($this->isExcluded($relative, $exclude)) {
                continue;
            }

            if ($file->isDir()) {
                continue;
            }

            $zip->addFile((string) $file->getPathname(), $relative);
        }

        $zip->close();
    }

    protected function backupDatabase(string $sqlPath): void
    {
        if (! (bool) config('updates.backup.database.enabled', true)) {
            return;
        }

        $connection = Config::get('database.default');
        $driver = Config::get("database.connections.{$connection}.driver");

        if ($driver !== 'mysql') {
            return;
        }

        $config = Config::get("database.connections.{$connection}");
        $binary = (string) (config('updates.backup.database.mysqldump_path') ?? 'mysqldump');

        $command = [
            $binary,
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            (string) ($config['database'] ?? ''),
        ];

        $process = new Process($command, base_path());
        $process->setEnv(['MYSQL_PWD' => (string) ($config['password'] ?? '')]);

        try {
            $process->run();
        } catch (\Throwable) {
            return;
        }

        if ($process->isSuccessful()) {
            file_put_contents($sqlPath, $process->getOutput());
        }
    }

    protected function restoreDatabase(string $sqlPath): void
    {
        if (! file_exists($sqlPath)) {
            return;
        }

        $connection = Config::get('database.default');
        $driver = Config::get("database.connections.{$connection}.driver");

        if ($driver !== 'mysql') {
            return;
        }

        $config = Config::get("database.connections.{$connection}");
        $binary = (string) (config('updates.backup.database.mysqldump_path') ?? 'mysqldump');
        $mysqlBin = preg_replace('/mysqldump$/', 'mysql', $binary) ?: 'mysql';

        $command = [
            $mysqlBin,
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? 'root'),
            (string) ($config['database'] ?? ''),
        ];

        $process = new Process($command, base_path());
        $process->setEnv(['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
        $process->setInput(file_get_contents($sqlPath));

        try {
            $process->run();
        } catch (\Throwable) {
            // Best-effort restore; surface failures via the rollback result instead.
        }
    }

    /**
     * @return list<string>
     */
    protected function excludedSegments(): array
    {
        $exclude = config('updates.backup.exclude', []);

        if ((bool) config('updates.backup.include_vendor', true) === false) {
            $exclude[] = 'vendor';
        }

        return array_map(fn ($s) => trim((string) $s, '/'), (array) $exclude);
    }

    /**
     * @param  list<string>  $exclude
     */
    protected function isExcluded(string $relativePath, array $exclude): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $firstSegment = explode('/', $relativePath)[0];

        foreach ($exclude as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');

            if ($pattern === '') {
                continue;
            }

            // Wildcard prefix: "foo/*" (or "foo*") matches anything under foo.
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($relativePath, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            // Exact top-level segment match (e.g. "vendor", "node_modules").
            if ($firstSegment === $pattern) {
                return true;
            }

            // Exact file match.
            if ($relativePath === $pattern) {
                return true;
            }

            // Directory prefix match: "storage/framework" excludes everything
            // beneath storage/framework/, including nested backups of backups.
            if (str_starts_with($relativePath, $pattern.'/')) {
                return true;
            }
        }

        return false;
    }
}
