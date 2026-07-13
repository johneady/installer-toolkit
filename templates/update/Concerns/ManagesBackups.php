<?php

/**
 * Pre-update backup (files + database) and its restore path, dependency-free.
 * Ported from the Laravel package's UpdateBackupManager, restructured so the
 * slow parts (zipping and re-extracting ~20k files) run in batches across
 * HTTP requests — the same gateway-timeout discipline the installer uses.
 *
 * Backups live in storage/app/updater/backups/{id}/ as files.zip +
 * database.sql + metadata.json.
 */
trait ManagesBackups
{
    /** Files added to the backup zip per request. */
    private const BACKUP_BATCH_FILES = 400;

    /** Wall-clock budget per backup/restore batch request, in seconds. */
    private const BATCH_BUDGET_SECONDS = 8;

    private function backupDir(string $backupId): string
    {
        if (! preg_match('/^[a-z0-9\-]+$/', $backupId)) {
            throw new RuntimeException('Invalid backup identifier.');
        }

        return $this->backupsDir().'/'.$backupId;
    }

    private function backupListFile(string $backupId): string
    {
        return $this->backupDir($backupId).'/file-list.txt';
    }

    private function newBackupId(): string
    {
        return date('Ymd-His').'-'.substr(bin2hex(random_bytes(4)), 0, 6);
    }

    /**
     * First backup call: enumerate everything to back up into a list file,
     * so subsequent batched calls can seek straight to their offset.
     * Returns the total file count.
     */
    private function buildBackupFileList(string $backupId): int
    {
        $dir = $this->backupDir($backupId);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            throw new RuntimeException('Failed to create the backup directory. Check storage permissions.');
        }

        $excluded = $this->backupExcludedSegments();
        $base = $this->appRoot();
        $list = fopen($this->backupListFile($backupId), 'wb');

        if ($list === false) {
            throw new RuntimeException('Failed to create the backup file list.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $count = 0;
        foreach ($iterator as $item) {
            if (! $item->isFile() || $item->isLink()) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($base) + 1);

            if ($this->isBackupExcluded($relative, $excluded)) {
                continue;
            }

            fwrite($list, $relative."\n");
            $count++;
        }

        fclose($list);

        file_put_contents($dir.'/metadata.json', json_encode([
            'id' => $backupId,
            'version' => $this->currentVersion(),
            'created_at' => date('c'),
            'files_total' => $count,
        ]));

        return $count;
    }

    /**
     * Append the next batch of listed files to the backup zip. Returns
     * [added_so_far, total, done].
     *
     * @return array{offset: int, total: int, done: bool}
     */
    private function appendBackupBatch(string $backupId, int $offset, int $total): array
    {
        $zipPath = $this->backupDir($backupId).'/files.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Failed to open the backup archive for writing.');
        }

        $base = $this->appRoot();
        $started = microtime(true);
        $added = 0;

        $list = new SplFileObject($this->backupListFile($backupId));
        $list->seek($offset);

        while (! $list->eof() && $added < self::BACKUP_BATCH_FILES && (microtime(true) - $started) < self::BATCH_BUDGET_SECONDS) {
            $relative = rtrim((string) $list->current(), "\n");
            $list->next();

            if ($relative === '') {
                $offset++;

                continue;
            }

            $absolute = $base.'/'.$relative;
            if (file_exists($absolute)) {
                $zip->addFile($absolute, $relative);
            }

            $added++;
            $offset++;
        }

        if (! $zip->close()) {
            throw new RuntimeException('Failed to write a backup batch. Check disk space.');
        }

        // The list file's trailing newline reads as one empty line past the
        // real entries — clamp so progress never reports past the total.
        $offset = min($offset, $total);

        return ['offset' => $offset, 'total' => $total, 'done' => $offset >= $total];
    }

    /**
     * Dump the database next to the files backup. MySQL/MariaDB uses
     * mysqldump via proc_open (password passed through the environment, not
     * argv); SQLite is a plain file copy. Returns a human-readable status —
     * a missing tool degrades to a files-only backup rather than blocking
     * the update.
     */
    private function backupDatabase(string $backupId): string
    {
        $backup = $this->backupConfig();

        if (! ($backup['database']['enabled'] ?? true)) {
            return 'Skipped (disabled in config).';
        }

        $db = $this->databaseSettings();
        $sqlPath = $this->backupDir($backupId).'/database.sql';

        if ($db['connection'] === 'sqlite') {
            $sqlitePath = $db['database'] !== '' && $db['database'][0] === '/'
                ? $db['database']
                : $this->appRoot().'/database/database.sqlite';

            if (! is_file($sqlitePath)) {
                return 'Skipped (SQLite database file not found).';
            }

            if (! copy($sqlitePath, $this->backupDir($backupId).'/database.sqlite')) {
                throw new RuntimeException('Failed to copy the SQLite database into the backup.');
            }

            return 'SQLite database copied.';
        }

        if (! in_array($db['connection'], ['mysql', 'mariadb'], true)) {
            return "Skipped (unsupported connection '{$db['connection']}').";
        }

        if (! function_exists('proc_open')) {
            return 'Skipped (proc_open is disabled on this server) — files-only backup.';
        }

        $binary = (string) ($backup['database']['mysqldump_path'] ?? 'mysqldump');

        $command = [
            $binary,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--user='.$db['username'],
            '--single-transaction',
            '--skip-lock-tables',
            // Since MySQL 5.7.31/8.0.21, dumping tablespace metadata requires
            // the PROCESS privilege, which shared-hosting DB users are almost
            // never granted — without this flag mysqldump exits non-zero and
            // the backup silently degrades to files-only. Triggers are dumped
            // by default; --routines is deliberately NOT passed because it
            // needs SELECT on mysql.proc / SHOW_ROUTINE, which those same
            // users typically lack — it would reintroduce the exact
            // privilege-based failure --no-tablespaces exists to avoid.
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$sqlPath,
            $db['database'],
        ];

        $exitCode = $this->runProcess($command, ['MYSQL_PWD' => $db['password']], $stderr);

        if ($exitCode !== 0) {
            @unlink($sqlPath);

            return 'Skipped (mysqldump failed: '.trim(substr($stderr, 0, 300)).') — files-only backup.';
        }

        return 'Database dumped ('.$this->formatBytes((int) filesize($sqlPath)).').';
    }

    /**
     * Restore a batch of files from the backup zip over the live tree.
     *
     * @return array{offset: int, total: int, done: bool}
     */
    private function restoreBackupBatch(string $backupId, int $offset): array
    {
        $zipPath = $this->backupDir($backupId).'/files.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Failed to open the backup archive for restore.');
        }

        $base = $this->appRoot();
        $total = $zip->numFiles;
        $started = microtime(true);
        $processed = 0;

        for ($i = $offset; $i < $total; $i++) {
            if ($processed >= self::BACKUP_BATCH_FILES || (microtime(true) - $started) >= self::BATCH_BUDGET_SECONDS) {
                break;
            }

            $name = (string) $zip->getNameIndex($i);
            $offset++;
            $processed++;

            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }

            $targetFile = $base.'/'.$name;
            $targetDir = dirname($targetFile);

            if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
                $zip->close();
                throw new RuntimeException("Failed to create {$targetDir} during restore.");
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Failed to read {$name} from the backup archive.");
            }

            $out = fopen($targetFile, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException("Failed to restore {$name}. Check permissions.");
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }

        $zip->close();

        return ['offset' => $offset, 'total' => $total, 'done' => $offset >= $total];
    }

    /**
     * Delete files that exist on disk but not in the backup — files a
     * partially-applied update introduced. Uses the backup's own file list
     * (not the zip index) for a fast membership set. Protected paths and
     * backup-excluded segments are never deleted.
     */
    private function deleteFilesAbsentFromBackup(string $backupId): int
    {
        $listFile = $this->backupListFile($backupId);
        if (! is_file($listFile)) {
            return 0;
        }

        $inBackup = [];
        foreach (file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $inBackup[$line] = true;
        }

        $excluded = $this->backupExcludedSegments();
        $base = $this->appRoot();
        $deleted = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if (! $item->isFile() || $item->isLink()) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($base) + 1);

            if (isset($inBackup[$relative])
                || $this->isBackupExcluded($relative, $excluded)
                || $this->isProtectedRelativePath($relative)) {
                continue;
            }

            if (@unlink($item->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Import the database dump taken before the update. Returns a status
     * string; like the dump itself, a missing tool degrades to a warning.
     */
    private function restoreDatabase(string $backupId): string
    {
        $dir = $this->backupDir($backupId);
        $db = $this->databaseSettings();

        if (is_file($dir.'/database.sqlite')) {
            $sqlitePath = $db['database'] !== '' && $db['database'][0] === '/'
                ? $db['database']
                : $this->appRoot().'/database/database.sqlite';

            return copy($dir.'/database.sqlite', $sqlitePath)
                ? 'SQLite database restored.'
                : 'WARNING: failed to restore the SQLite database file.';
        }

        $sqlPath = $dir.'/database.sql';

        if (! is_file($sqlPath)) {
            return 'No database dump in this backup — database left as-is.';
        }

        if (! function_exists('proc_open')) {
            return 'WARNING: proc_open is disabled; restore the database manually from '.$sqlPath;
        }

        $backup = $this->backupConfig();
        $binary = (string) ($backup['database']['mysqldump_path'] ?? 'mysqldump');
        // The mysql client lives next to mysqldump.
        $mysql = preg_replace('/mysqldump(\.exe)?$/', 'mysql$1', $binary) ?: 'mysql';

        // Drop every existing table first. The dump only DROPs tables it
        // contains, so a table created by the failed update's migrations
        // (but absent from the pre-update dump) would otherwise survive the
        // restore — and a later retry of the same update then fails at
        // `migrate` with "table already exists", stranding the site.
        $dropWarning = $this->dropAllTables($mysql, $db);

        $command = [
            $mysql,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--user='.$db['username'],
            $db['database'],
        ];

        $exitCode = $this->runProcess($command, ['MYSQL_PWD' => $db['password']], $stderr, $sqlPath);

        return $exitCode === 0
            ? 'Database restored from dump.'.$dropWarning
            : 'WARNING: database restore failed ('.trim(substr($stderr, 0, 300)).'). Restore manually from '.$sqlPath;
    }

    /**
     * Drop every table currently in the database before importing a restore
     * dump, using a single `mysql` invocation fed a generated
     * `SET FOREIGN_KEY_CHECKS=0; DROP TABLE ...;` script (disabling FK
     * checks for the duration avoids drop-order errors on tables with
     * cross-references). Best-effort: a failure here is surfaced as a
     * warning appended to the restore's own success message rather than
     * aborting the restore, since the dump import that follows is what
     * actually matters for getting the site back up.
     *
     * @param  array{connection: string, host: string, port: string, database: string, username: string, password: string}  $db
     */
    private function dropAllTables(string $mysql, array $db): string
    {
        $listCommand = [
            $mysql,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--user='.$db['username'],
            '--batch',
            '--skip-column-names',
            '--execute=SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
            $db['database'],
        ];

        $listExitCode = $this->runProcess($listCommand, ['MYSQL_PWD' => $db['password']], $listStderr, null, $tables);

        if ($listExitCode !== 0) {
            return ' WARNING: could not enumerate existing tables before restore ('.trim(substr($listStderr, 0, 200)).') — restore may fail if the update left extra tables behind.';
        }

        $tableNames = array_filter(array_map('trim', explode("\n", $tables)));

        if ($tableNames === []) {
            return '';
        }

        $dropScript = "SET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($tableNames as $table) {
            $dropScript .= 'DROP TABLE IF EXISTS `'.str_replace('`', '``', $table)."`;\n";
        }
        $dropScript .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $scriptPath = $this->updaterStorageDir().'/restore-drop-'.bin2hex(random_bytes(4)).'.sql';
        file_put_contents($scriptPath, $dropScript);

        $dropCommand = [
            $mysql,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--user='.$db['username'],
            $db['database'],
        ];

        $exitCode = $this->runProcess($dropCommand, ['MYSQL_PWD' => $db['password']], $dropStderr, $scriptPath);
        @unlink($scriptPath);

        return $exitCode === 0
            ? ''
            : ' WARNING: failed to drop existing tables before restore ('.trim(substr($dropStderr, 0, 200)).') — the dump import may fail if the update left extra tables behind.';
    }

    /**
     * Keep the newest $keep backups, delete the rest. Returns how many were
     * pruned.
     */
    private function pruneBackups(int $keep): int
    {
        $dirs = glob($this->backupsDir().'/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs); // ids sort chronologically (Ymd-His prefix)

        $pruned = 0;
        foreach (array_slice($dirs, max(0, $keep)) as $dir) {
            $this->deleteDirectoryRecursive($dir);
            $pruned++;
        }

        return $pruned;
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function backupExcludedSegments(): array
    {
        $backup = $this->backupConfig();
        $exclude = array_values(array_map('strval', (array) ($backup['exclude'] ?? [])));

        // The updater's own working area (uploads, staging, backups) must
        // never be swallowed into a backup of itself.
        $exclude[] = 'storage/app/updater';

        if (($backup['include_vendor'] ?? true) === false) {
            $exclude[] = 'vendor';
        }

        return $exclude;
    }

    /**
     * @param  list<string>  $excluded
     */
    private function isBackupExcluded(string $relativePath, array $excluded): bool
    {
        foreach ($excluded as $segment) {
            $segment = rtrim($segment, '/');

            if ($segment === '') {
                continue;
            }

            // Segments may be a root-relative prefix ('storage/logs') or a
            // bare directory name matched anywhere ('node_modules'), same as
            // the Laravel-side backup manager.
            if ($relativePath === $segment || str_starts_with($relativePath, $segment.'/')) {
                return true;
            }

            if (! str_contains($segment, '/') && str_contains($relativePath, '/'.$segment.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run an external process with stdin optionally fed from a file and
     * stderr captured. Returns the exit code; stdout is captured into the
     * optional $stdout out-param (discarded when the caller doesn't ask).
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $extraEnv
     */
    private function runProcess(array $command, array $extraEnv, ?string &$stderr, ?string $stdinFile = null, ?string &$stdout = null): int
    {
        $descriptors = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_SERVER['PATH'] ?? false ? ['PATH' => $_SERVER['PATH']] : [], $extraEnv);
        // Preserve the inherited environment too — proc_open with an env
        // array replaces it entirely otherwise.
        foreach ($_ENV as $k => $v) {
            if (is_string($v) && ! isset($env[$k])) {
                $env[$k] = $v;
            }
        }

        $process = @proc_open($command, $descriptors, $pipes, $this->appRoot(), $env);

        if (! is_resource($process)) {
            $stderr = 'Failed to start process: '.$command[0];

            return 127;
        }

        if ($stdinFile === null && isset($pipes[0])) {
            fclose($pipes[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
