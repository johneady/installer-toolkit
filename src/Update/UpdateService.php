<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use InstallerToolkit\Update\Events\UpdateCompleted;
use InstallerToolkit\Update\Events\UpdateFailed;
use InstallerToolkit\Update\Events\UpdateRolledBack;
use InstallerToolkit\Update\Events\UpdateStarted;
use InstallerToolkit\Update\Models\UpdateHistory;
use ZipArchive;

class UpdateService
{
    private ?bool $historyAvailable = null;

    public function __construct(
        protected UpdateBackupManager $backups,
    ) {}

    /**
     * Validate an uploaded .update package.
     */
    public function validateUpdateZip(string $zipPath): UpdateValidationResult
    {
        $currentVersion = $this->getCurrentVersion();

        if (! file_exists($zipPath)) {
            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: 'Update file not found.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: 'Unable to open update file. The zip may be corrupted.');
        }

        $manifestContent = $zip->getFromName('manifest.json');
        if ($manifestContent === false) {
            $zip->close();

            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: 'Invalid update package: missing manifest.json.');
        }

        $manifest = json_decode($manifestContent, true);
        if (! is_array($manifest)) {
            $zip->close();

            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: 'Invalid update package: manifest.json is malformed.');
        }

        if (($manifest['type'] ?? '') !== 'update') {
            $zip->close();

            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: 'Invalid update package: not an update package.');
        }

        $updateVersion = (string) ($manifest['version'] ?? '');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $updateVersion)) {
            $zip->close();

            return new UpdateValidationResult(valid: false, currentVersion: $currentVersion, error: "Invalid version format in update package: '{$updateVersion}'.");
        }

        if (version_compare($updateVersion, $currentVersion, '<=')) {
            $zip->close();

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: "Update version ({$updateVersion}) must be newer than the current version ({$currentVersion}).",
            );
        }

        $minimumVersion = (string) ($manifest['minimum_version'] ?? '1.0.0');
        if (version_compare($currentVersion, $minimumVersion, '<')) {
            $zip->close();

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: "This update requires version {$minimumVersion} or later. You are running {$currentVersion}.",
            );
        }

        $minimumPhp = (string) ($manifest['minimum_php'] ?? '8.2.0');
        if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
            $zip->close();

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: "This update requires PHP {$minimumPhp} or later. You are running PHP ".PHP_VERSION.'.',
            );
        }

        $innerZipName = $this->innerZipName();

        if ($zip->locateName($innerZipName) === false) {
            $zip->close();

            return new UpdateValidationResult(valid: false, version: $updateVersion, currentVersion: $currentVersion, error: "Invalid update package: missing {$innerZipName}.");
        }

        // Copy the inner zip to a temp file via a stream — real packages run
        // to hundreds of MB, and getFromName() would load the whole thing
        // into memory (fatal under a shared host's memory_limit). The temp
        // copy serves both the checksum and the traversal check below.
        $tempInnerPath = storage_path('app/update-check-'.uniqid().'.zip');

        try {
            $this->copyZipEntryToFile($zip, $innerZipName, $tempInnerPath);
        } catch (\RuntimeException) {
            $zip->close();
            @unlink($tempInnerPath);

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: 'Failed to read the inner update archive. The file may be corrupted.',
            );
        }

        $zip->close();

        $expectedChecksum = (string) ($manifest['checksum'] ?? '');
        if ($expectedChecksum !== '' && hash_file('sha256', $tempInnerPath) !== $expectedChecksum) {
            @unlink($tempInnerPath);

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: 'Update package integrity check failed. The file may be corrupted.',
            );
        }

        $signatureError = $this->verifySignature($manifest, $updateVersion, $minimumVersion, $expectedChecksum);
        if ($signatureError !== null) {
            @unlink($tempInnerPath);

            return new UpdateValidationResult(
                valid: false,
                version: $updateVersion,
                currentVersion: $currentVersion,
                error: $signatureError,
            );
        }

        // Verify no path traversal in the inner zip.

        $innerZip = new ZipArchive;
        if ($innerZip->open($tempInnerPath) === true) {
            for ($i = 0; $i < $innerZip->numFiles; $i++) {
                $name = (string) $innerZip->getNameIndex($i);
                if (str_contains($name, '..')) {
                    $innerZip->close();
                    @unlink($tempInnerPath);

                    return new UpdateValidationResult(
                        valid: false,
                        version: $updateVersion,
                        currentVersion: $currentVersion,
                        error: 'Update package contains invalid file paths.',
                    );
                }
            }
            $innerZip->close();
        }
        @unlink($tempInnerPath);

        return new UpdateValidationResult(
            valid: true,
            version: $updateVersion,
            currentVersion: $currentVersion,
            filesCount: isset($manifest['files_count']) ? (int) $manifest['files_count'] : null,
            minimumPhp: $minimumPhp,
        );
    }

    /**
     * Verify the manifest's Ed25519 signature. Returns null when the package
     * is acceptable (either verified, or no trusted keys are configured yet
     * so verification is not required), or an error message when it must be
     * rejected.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifySignature(array $manifest, string $version, string $minimumVersion, string $checksum): ?string
    {
        $trustedKeys = array_map('strval', (array) config('updates.signing.trusted_keys', []));

        if ($trustedKeys === []) {
            Log::warning('Update package accepted without signature verification: no trusted signing keys are configured.');

            return null;
        }

        if (! extension_loaded('sodium')) {
            return 'Signature verification is required but the sodium PHP extension is not available on this server.';
        }

        $signature = (string) ($manifest['signature'] ?? '');
        $keyId = (string) ($manifest['key_id'] ?? '');

        if ($signature === '' || $keyId === '') {
            return 'This update package is not signed, but signature verification is required on this server.';
        }

        if (! isset($trustedKeys[$keyId])) {
            return "This update package is signed with an unrecognized key ('{$keyId}').";
        }

        $payload = UpdateSignature::canonicalPayload($this->slug(), $version, $minimumVersion, $checksum, $keyId);

        if (! UpdateSignature::verify($payload, $signature, $keyId, $trustedKeys)) {
            return 'Update package signature verification failed. The file may have been tampered with.';
        }

        return null;
    }

    /**
     * Read the raw manifest from an update package (for audit logging).
     *
     * @return array<string, mixed>|null
     */
    public function readManifest(string $zipPath): ?array
    {
        if (! file_exists($zipPath)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $content = $zip->getFromName('manifest.json');
        $zip->close();

        $data = json_decode((string) $content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Extract the inner zip from the update package into staging.
     */
    public function stageUpdate(string $zipPath, string $uploadId): void
    {
        $stagingDir = $this->stagingDir($uploadId);

        if (is_dir($stagingDir)) {
            File::deleteDirectory($stagingDir);
        }

        File::ensureDirectoryExists($stagingDir);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open update zip for staging.');
        }

        $innerZipPath = $stagingDir.'/'.$this->innerZipName();

        try {
            // Stream rather than getFromName() — see validateUpdateZip().
            $this->copyZipEntryToFile($zip, $this->innerZipName(), $innerZipPath);
        } finally {
            $zip->close();
        }

        $this->saveProgress($uploadId, 0, 0);
    }

    /**
     * Stream a single zip entry to a file on disk without buffering the
     * whole entry in memory.
     */
    private function copyZipEntryToFile(ZipArchive $zip, string $entryName, string $targetPath): void
    {
        $source = $zip->getStream($entryName);
        if ($source === false) {
            throw new \RuntimeException("Failed to read inner update archive ({$entryName}).");
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new \RuntimeException("Failed to open {$targetPath} for writing.");
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if ($copied === false) {
            @unlink($targetPath);
            throw new \RuntimeException("Failed to write {$targetPath}. Check disk space and permissions.");
        }
    }

    /**
     * Extract a batch of files from the staged inner zip over the live app.
     *
     * @return array{done: bool, extracted: int, total: int}
     */
    public function extractBatch(string $uploadId, int $batchSize = 500, ?string $targetPath = null): array
    {
        $stagingDir = $this->stagingDir($uploadId);
        $innerZipPath = $stagingDir.'/'.$this->innerZipName();

        if (! file_exists($innerZipPath)) {
            throw new \RuntimeException('No staged update found. Run stageUpdate first.');
        }

        $zip = new ZipArchive;
        if ($zip->open($innerZipPath) !== true) {
            throw new \RuntimeException('Failed to open staged update zip.');
        }

        $totalFiles = $zip->numFiles;
        $progress = $this->loadProgress($uploadId);
        $offset = $progress['extracted'];
        $extracted = 0;
        $basePath = $targetPath ?? base_path();
        $appPrefix = $this->appPrefix();
        $appPrefixLen = strlen($appPrefix);

        for ($i = $offset; $i < $totalFiles && $extracted < $batchSize; $i++) {
            $entryName = (string) $zip->getNameIndex($i);

            if (str_ends_with($entryName, '/')) {
                $offset++;

                continue;
            }

            if (str_starts_with($entryName, $appPrefix)) {
                $relativePath = substr($entryName, $appPrefixLen);
            } else {
                $offset++;

                continue;
            }

            if ($this->isProtectedPath($relativePath)) {
                $offset++;

                continue;
            }

            $targetFile = $basePath.'/'.$relativePath;
            $targetDir = dirname($targetFile);

            if (! is_dir($targetDir)) {
                File::ensureDirectoryExists($targetDir);
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $zip->close();
                throw new \RuntimeException("Failed to read {$entryName} from the update archive. The file may be corrupted.");
            }

            if (file_put_contents($targetFile, $content) === false) {
                $zip->close();
                throw new \RuntimeException("Failed to write {$relativePath}. Check disk space and permissions.");
            }

            $extracted++;
            $offset++;
        }

        $zip->close();

        $this->saveProgress($uploadId, $offset, $totalFiles);

        return [
            'done' => $offset >= $totalFiles,
            'extracted' => $offset,
            'total' => $totalFiles,
        ];
    }

    /**
     * Run pending database migrations.
     */
    public function runMigrations(): string
    {
        Artisan::call('migrate', ['--force' => true]);

        return Artisan::output();
    }

    /**
     * Clear all application caches.
     */
    public function clearCaches(): string
    {
        return $this->runCommands((array) config('updates.clear_cache_commands', []));
    }

    /**
     * Run optimization commands. Failures are recorded as warnings.
     */
    public function optimize(): string
    {
        $output = '';

        foreach ((array) config('updates.optimize_commands', []) as $command) {
            try {
                Artisan::call($command);
                $output .= Artisan::output();
            } catch (Throwable $e) {
                $output .= "Warning: {$command} failed: {$e->getMessage()}\n";
            }
        }

        return $output;
    }

    /**
     * Verify the update was applied: the new version must be the active one.
     *
     * Reads config('app.version') (fresh after cache clear, and correct so long
     * as the app uses a hardcoded version literal in config/app.php) and also
     * confirms the literal is present on disk.
     */
    public function verify(string $expectedVersion): bool
    {
        if ($this->getCurrentVersion() === $expectedVersion) {
            return true;
        }

        $configPath = base_path('config/app.php');
        if (! file_exists($configPath)) {
            return false;
        }

        $configContent = (string) file_get_contents($configPath);

        return str_contains($configContent, "'version' => '{$expectedVersion}'")
            || str_contains($configContent, '"version" => "'.$expectedVersion.'"');
    }

    /**
     * Clean up staging files, progress, and the uploaded zip.
     */
    public function cleanup(string $uploadId, ?string $uploadedZipPath = null): void
    {
        $stagingDir = $this->stagingDir($uploadId);
        if (is_dir($stagingDir)) {
            File::deleteDirectory($stagingDir);
        }

        $progressFile = $this->progressFile($uploadId);
        if (file_exists($progressFile)) {
            @unlink($progressFile);
        }

        if ($uploadedZipPath && file_exists($uploadedZipPath)) {
            @unlink($uploadedZipPath);
        }
    }

    public function getCurrentVersion(): string
    {
        return (string) config('app.version');
    }

    public function pendingUpdatePath(string $uploadId): string
    {
        return $this->uploadScopedPath('pending-update', $uploadId, '.update');
    }

    // ----------------------------------------------------------------------
    // Backup integration
    // ----------------------------------------------------------------------

    public function createBackup(): string
    {
        return $this->backups->create();
    }

    public function restoreBackup(string $backupId, bool $skipDatabase = false): void
    {
        $this->backups->restore($backupId, $skipDatabase);
    }

    public function pruneBackups(int $keep): int
    {
        return $this->backups->prune($keep);
    }

    /**
     * @return list<array{id: string, version: ?string, created_at: ?string, has_database: bool, size: int}>
     */
    public function listBackups(): array
    {
        return $this->backups->listBackups();
    }

    public function backupExists(?string $backupId): bool
    {
        if (! $backupId) {
            return false;
        }

        return $this->backups->exists($backupId);
    }

    /**
     * Roll back to a previous backup: restore files (and optionally the
     * database), clear caches, mark the associated history record as
     * rolled back, and dispatch the UpdateRolledBack event.
     */
    public function rollback(string $backupId, bool $skipDatabase = false): void
    {
        $this->restoreBackup($backupId, $skipDatabase);
        $this->clearCaches();

        if (! $this->historyAvailable()) {
            return;
        }

        $history = UpdateHistory::where('backup_id', $backupId)->latest()->first();

        if ($history instanceof UpdateHistory) {
            $this->markRolledBack($history);
            $this->dispatchRolledBack($history);
        }
    }

    // ----------------------------------------------------------------------
    // History integration (graceful: no-ops if the table is absent)
    // ----------------------------------------------------------------------

    public function startHistory(string $versionFrom, string $versionTo, ?string $backupId = null, ?array $manifest = null): ?UpdateHistory
    {
        if (! $this->historyAvailable()) {
            return null;
        }

        return UpdateHistory::create([
            'version_from' => $versionFrom,
            'version_to' => $versionTo,
            'backup_id' => $backupId,
            'status' => UpdateHistory::STATUS_IN_PROGRESS,
            'manifest' => $manifest,
        ]);
    }

    public function completeHistory(?UpdateHistory $history): void
    {
        if (! $history instanceof UpdateHistory) {
            return;
        }

        $history->update(['status' => UpdateHistory::STATUS_APPLIED, 'error' => null]);
    }

    public function failHistory(?UpdateHistory $history, string $error): void
    {
        if (! $history instanceof UpdateHistory) {
            return;
        }

        $history->update(['status' => UpdateHistory::STATUS_FAILED, 'error' => $error]);
    }

    public function markRolledBack(?UpdateHistory $history): void
    {
        if (! $history instanceof UpdateHistory) {
            return;
        }

        $history->update(['status' => UpdateHistory::STATUS_ROLLED_BACK]);
    }

    // ----------------------------------------------------------------------
    // Event dispatch helpers (sugar for the page/command layer)
    // ----------------------------------------------------------------------

    public function dispatchStarted(string $from, string $to, ?string $backupId): void
    {
        Event::dispatch(new UpdateStarted($from, $to, $backupId));
    }

    public function dispatchCompleted(UpdateHistory $history): void
    {
        Event::dispatch(new UpdateCompleted($history));
    }

    public function dispatchFailed(string $from, string $to, Throwable $e, ?string $backupId = null): void
    {
        Event::dispatch(new UpdateFailed($from, $to, $e, $backupId));
    }

    public function dispatchRolledBack(UpdateHistory $history): void
    {
        Event::dispatch(new UpdateRolledBack($history));
    }

    // ----------------------------------------------------------------------
    // Path helpers
    // ----------------------------------------------------------------------

    private function stagingDir(string $uploadId): string
    {
        return $this->uploadScopedPath('update-staging', $uploadId);
    }

    private function progressFile(string $uploadId): string
    {
        return $this->uploadScopedPath('update-progress', $uploadId, '.json');
    }

    private function uploadScopedPath(string $prefix, string $uploadId, string $suffix = ''): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new \InvalidArgumentException('Invalid upload identifier.');
        }

        return storage_path("app/{$prefix}-{$uploadId}{$suffix}");
    }

    private function isProtectedPath(string $relativePath): bool
    {
        foreach ($this->protectedPaths() as $protected) {
            if (str_ends_with($protected, '/') && str_starts_with($relativePath, $protected)) {
                return true;
            }

            if ($relativePath === $protected) {
                return true;
            }
        }

        if (str_starts_with($relativePath, 'database/') && str_ends_with($relativePath, '.sqlite')) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function protectedPaths(): array
    {
        return array_values(array_map('strval', (array) config('updates.protected_paths', [])));
    }

    private function slug(): string
    {
        return (string) config('updates.slug', 'app');
    }

    private function innerZipName(): string
    {
        return $this->slug().'.zip';
    }

    private function appPrefix(): string
    {
        return $this->slug().'/';
    }

    private function runCommands(array $commands): string
    {
        $output = '';
        foreach ($commands as $command) {
            Artisan::call($command);
            $output .= Artisan::output();
        }

        return $output;
    }

    private function saveProgress(string $uploadId, int $extracted, int $total): void
    {
        file_put_contents($this->progressFile($uploadId), json_encode([
            'extracted' => $extracted,
            'total' => $total,
        ]));
    }

    /**
     * @return array{extracted: int, total: int}
     */
    private function loadProgress(string $uploadId): array
    {
        $progressFile = $this->progressFile($uploadId);
        if (! file_exists($progressFile)) {
            return ['extracted' => 0, 'total' => 0];
        }

        $data = json_decode((string) file_get_contents($progressFile), true);

        return [
            'extracted' => (int) ($data['extracted'] ?? 0),
            'total' => (int) ($data['total'] ?? 0),
        ];
    }

    private function historyAvailable(): bool
    {
        if ($this->historyAvailable !== null) {
            return $this->historyAvailable;
        }

        try {
            return $this->historyAvailable = Schema::hasTable('update_history');
        } catch (Throwable) {
            return $this->historyAvailable = false;
        }
    }
}
