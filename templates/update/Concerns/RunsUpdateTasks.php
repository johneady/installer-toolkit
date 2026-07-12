<?php

/**
 * The update pipeline, one AJAX task per unit of work:
 *
 *   prepare → backup_files* → backup_db → extract* → post_update → verify → finalize
 *
 * Tasks marked * are batched: they return task_done=false and the browser
 * calls the same task again until it reports done — the installer's
 * gateway-timeout discipline. The framework is never booted during any of
 * this except post_update, which runs the hook shipped inside the signed
 * package after every file is already in place.
 *
 * On failure the browser offers a restore, which replays the pre-update
 * backup (files, deletions, database) the same batched way.
 */
trait RunsUpdateTasks
{
    private const EXTRACT_BATCH_FILES = 400;

    /** Locks stale after this many seconds are considered abandoned. */
    private const LOCK_STALE_SECONDS = 1800;

    private function lockFile(): string
    {
        return $this->updaterStorageDir().'/update.lock';
    }

    private function handleUpdateTask(string $task): void
    {
        header('Content-Type: application/json');

        $update = &$_SESSION['updater']['update'];

        if (empty($update['started'])) {
            echo json_encode(['success' => false, 'message' => 'No update run in progress.']);
            exit;
        }

        $completed = $update['completed_tasks'] ?? [];
        if (in_array($task, $completed, true) && $task !== 'restore') {
            echo json_encode(['success' => true, 'task_done' => true, 'message' => 'Already completed.']);
            exit;
        }

        try {
            $result = match ($task) {
                'prepare' => $this->taskPrepare(),
                'backup_files' => $this->taskBackupFiles(),
                'backup_db' => $this->taskBackupDb(),
                'extract' => $this->taskExtract(),
                'post_update' => $this->taskPostUpdate(),
                'verify' => $this->taskVerify(),
                'finalize' => $this->taskFinalize(),
                'restore' => $this->taskRestore(),
                default => throw new RuntimeException('Unknown task.'),
            };

            if (($result['task_done'] ?? true) && $task !== 'restore') {
                $update['completed_tasks'][] = $task;
            }

            echo json_encode(array_merge(['success' => true, 'task_done' => true], $result));
        } catch (Throwable $e) {
            $update['failed'] = ['task' => $task, 'message' => $e->getMessage()];

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'can_restore' => $this->backupIsUsable(),
            ]);
        }

        exit;
    }

    private function backupIsUsable(): bool
    {
        $update = $_SESSION['updater']['update'] ?? [];

        return in_array('backup_files', $update['completed_tasks'] ?? [], true)
            && ! empty($update['backup_id']);
    }

    // ------------------------------------------------------------------
    // Forward pipeline
    // ------------------------------------------------------------------

    private function taskPrepare(): array
    {
        $lock = $this->lockFile();

        if (file_exists($lock)) {
            $age = time() - (int) filemtime($lock);
            $ownLock = hash_equals((string) ($_SESSION['updater']['update']['lock_token'] ?? ''), trim((string) file_get_contents($lock)));

            if (! $ownLock && $age < self::LOCK_STALE_SECONDS) {
                throw new RuntimeException('Another update appears to be in progress (lock held for '.$age.'s). Wait for it to finish, or delete storage/app/updater/update.lock if it crashed.');
            }
        }

        $lockToken = bin2hex(random_bytes(16));
        if (@file_put_contents($lock, $lockToken) === false) {
            throw new RuntimeException('Failed to write the update lock file.');
        }
        $_SESSION['updater']['update']['lock_token'] = $lockToken;

        $this->enableMaintenanceMode();

        return ['message' => 'Maintenance mode enabled.'];
    }

    private function taskBackupFiles(): array
    {
        $update = &$_SESSION['updater']['update'];
        $backup = $this->backupConfig();

        if (! ($backup['enabled'] ?? true)) {
            return ['message' => 'Skipped (backups disabled in config).'];
        }

        if (empty($update['backup_id'])) {
            $backupId = $this->newBackupId();
            $total = $this->buildBackupFileList($backupId);

            $update['backup_id'] = $backupId;
            $update['backup_total'] = $total;
            $update['backup_offset'] = 0;

            return [
                'task_done' => false,
                'message' => "Cataloged {$total} files to back up…",
            ];
        }

        $state = $this->appendBackupBatch(
            $update['backup_id'],
            (int) $update['backup_offset'],
            (int) $update['backup_total']
        );

        $update['backup_offset'] = $state['offset'];

        return [
            'task_done' => $state['done'],
            'message' => "Backed up {$state['offset']}/{$state['total']} files",
        ];
    }

    private function taskBackupDb(): array
    {
        $update = $_SESSION['updater']['update'];
        $backup = $this->backupConfig();

        if (! ($backup['enabled'] ?? true) || empty($update['backup_id'])) {
            return ['message' => 'Skipped (backups disabled).'];
        }

        return ['message' => $this->backupDatabase($update['backup_id'])];
    }

    private function taskExtract(): array
    {
        $update = &$_SESSION['updater']['update'];
        $package = $_SESSION['updater']['package'] ?? [];

        $stagedZip = (string) ($package['staged_zip'] ?? '');
        if ($stagedZip === '' || ! file_exists($stagedZip)) {
            throw new RuntimeException('The staged update archive is missing. Upload the package again.');
        }

        $zip = new ZipArchive;
        if ($zip->open($stagedZip) !== true) {
            throw new RuntimeException('Failed to open the staged update archive.');
        }

        $total = $zip->numFiles;
        $offset = (int) ($update['extract_offset'] ?? 0);
        $base = $this->appRoot();
        $prefix = $this->slug().'/';
        $prefixLen = strlen($prefix);
        $started = microtime(true);
        $processed = 0;

        for ($i = $offset; $i < $total; $i++) {
            if ($processed >= self::EXTRACT_BATCH_FILES || (microtime(true) - $started) >= self::BATCH_BUDGET_SECONDS) {
                break;
            }

            $entryName = (string) $zip->getNameIndex($i);
            $offset++;
            $processed++;

            if ($entryName === '' || str_ends_with($entryName, '/') || ! str_starts_with($entryName, $prefix)) {
                continue;
            }

            $relative = substr($entryName, $prefixLen);

            if ($this->isSkippedDuringExtraction($relative)) {
                continue;
            }

            $targetFile = $base.'/'.$relative;
            $targetDir = dirname($targetFile);

            if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
                $zip->close();
                throw new RuntimeException("Failed to create directory {$targetDir}.");
            }

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Failed to read {$entryName} from the update archive. The file may be corrupted.");
            }

            $out = fopen($targetFile, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException("Failed to write {$relative}. Check disk space and permissions.");
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }

        $zip->close();

        $update['extract_offset'] = $offset;
        $update['extract_total'] = $total;

        return [
            'task_done' => $offset >= $total,
            'message' => "Extracted {$offset}/{$total} files",
            'percent' => $total > 0 ? (int) round(($offset / $total) * 100) : 100,
        ];
    }

    /**
     * Paths never overwritten by extraction: the operator's protected paths
     * from config, SQLite data files, the updater's own working area, and
     * updater.php itself (this very script, executing right now — the fresh
     * copy is swapped in at finalize, when nothing else remains to run).
     */
    private function isSkippedDuringExtraction(string $relative): bool
    {
        if ($relative === 'public/updater.php') {
            return true;
        }

        if (str_starts_with($relative, 'storage/app/updater/')) {
            return true;
        }

        return $this->isProtectedRelativePath($relative);
    }

    private function isProtectedRelativePath(string $relative): bool
    {
        foreach ($this->protectedPaths() as $protected) {
            if (str_ends_with($protected, '/') && str_starts_with($relative, $protected)) {
                return true;
            }

            if ($relative === $protected) {
                return true;
            }
        }

        if (str_starts_with($relative, 'database/') && str_ends_with($relative, '.sqlite')) {
            return true;
        }

        return false;
    }

    /**
     * Run the post-update hook the package shipped inside its signed inner
     * zip (extracted to .updater/post_update.php). The hook boots the
     * freshly-updated application exactly once and runs migrations plus the
     * cache/optimize commands from the app's own config.
     */
    private function taskPostUpdate(): array
    {
        $hook = $this->appRoot().'/.updater/post_update.php';

        if (! is_file($hook)) {
            throw new RuntimeException('The update package did not provide a post-update hook (.updater/post_update.php). It may have been built with an older toolkit.');
        }

        ob_start();

        try {
            $result = require $hook;
        } finally {
            ob_end_clean();
        }

        if (! is_array($result) || ! array_key_exists('success', $result)) {
            throw new RuntimeException('The post-update hook returned an unexpected result.');
        }

        if (! $result['success']) {
            throw new RuntimeException('Post-update failed: '.(string) ($result['output'] ?? 'unknown error'));
        }

        return ['message' => 'Migrations and caches complete.'];
    }

    private function taskVerify(): array
    {
        $expected = (string) ($_SESSION['updater']['update']['version_to'] ?? '');
        $actual = $this->currentVersion();

        if ($expected === '' || $actual !== $expected) {
            throw new RuntimeException("Version verification failed: expected v{$expected}, found v".($actual ?? 'unknown').'. The update may not have been applied correctly.');
        }

        return ['message' => "v{$expected} verified"];
    }

    private function taskFinalize(): array
    {
        $update = &$_SESSION['updater']['update'];
        $package = $_SESSION['updater']['package'] ?? [];

        // Swap in the fresh updater shipped inside the package. This
        // overwrites the currently-executing file, which is safe: this
        // request's code is already loaded, and every later request parses
        // the new copy.
        $this->swapInNewUpdater((string) ($package['staged_zip'] ?? ''));

        $this->writeResult('applied', [
            'version_from' => $update['version_from'] ?? null,
            'version_to' => $update['version_to'] ?? null,
            'backup_id' => $update['backup_id'] ?? null,
            'started_at' => $update['started_at'] ?? null,
        ]);

        foreach ([(string) ($package['path'] ?? ''), (string) ($package['staged_zip'] ?? '')] as $file) {
            if ($file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }

        $keep = (int) ($this->backupConfig()['keep'] ?? 3);
        $this->pruneBackups(max(1, $keep));

        $this->disableMaintenanceMode();
        @unlink($this->lockFile());

        $update['finished'] = true;
        $_SESSION['updater']['done'] = [
            'version' => $update['version_to'] ?? null,
            'version_from' => $update['version_from'] ?? null,
            'finished_at' => date('c'),
        ];
        unset($_SESSION['updater']['update'], $_SESSION['updater']['package']);

        return ['message' => 'Application is live again.'];
    }

    private function swapInNewUpdater(string $stagedZip): void
    {
        if ($stagedZip === '' || ! file_exists($stagedZip)) {
            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($stagedZip) !== true) {
            return;
        }

        $entry = $this->slug().'/public/updater.php';

        if ($zip->locateName($entry) !== false) {
            $content = $zip->getFromName($entry);

            if ($content !== false && $content !== '') {
                @file_put_contents($this->appRoot().'/public/updater.php', $content);
            }
        }

        $zip->close();
    }

    /**
     * Append the run's outcome to storage/app/updater/results/ — the
     * updater's own history, and the file the application ingests into its
     * update_history table on its next boot.
     */
    private function writeResult(string $status, array $extra): void
    {
        $payload = array_merge([
            'status' => $status,
            'finished_at' => date('c'),
            'updater_version' => UPDATER_VERSION,
        ], $extra);

        @file_put_contents(
            $this->resultsDir().'/'.date('Ymd-His').'-'.$status.'.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // ------------------------------------------------------------------
    // Restore (after a failed run)
    // ------------------------------------------------------------------

    private function taskRestore(): array
    {
        $update = &$_SESSION['updater']['update'];

        if (! $this->backupIsUsable()) {
            throw new RuntimeException('No usable backup to restore from.');
        }

        $backupId = (string) $update['backup_id'];
        $phase = (string) ($update['restore_phase'] ?? 'files');

        if ($phase === 'files') {
            $state = $this->restoreBackupBatch($backupId, (int) ($update['restore_offset'] ?? 0));
            $update['restore_offset'] = $state['offset'];

            if (! $state['done']) {
                return ['task_done' => false, 'message' => "Restored {$state['offset']}/{$state['total']} files"];
            }

            $update['restore_phase'] = 'cleanup';

            return ['task_done' => false, 'message' => 'Files restored. Cleaning up…'];
        }

        if ($phase === 'cleanup') {
            $deleted = $this->deleteFilesAbsentFromBackup($backupId);
            $update['restore_phase'] = 'database';

            return ['task_done' => false, 'message' => "Removed {$deleted} files the update had added. Restoring database…"];
        }

        // database + bring the site back up
        $dbMessage = $this->restoreDatabase($backupId);

        $this->writeResult('rolled_back', [
            'version_from' => $update['version_from'] ?? null,
            'version_to' => $update['version_to'] ?? null,
            'backup_id' => $backupId,
            'failed_task' => $update['failed']['task'] ?? null,
            'error' => $update['failed']['message'] ?? null,
        ]);

        $this->disableMaintenanceMode();
        @unlink($this->lockFile());

        unset($_SESSION['updater']['update'], $_SESSION['updater']['package']);

        return ['task_done' => true, 'message' => 'Restore complete. '.$dbMessage];
    }
}
