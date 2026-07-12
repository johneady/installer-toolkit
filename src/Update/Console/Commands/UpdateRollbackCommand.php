<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;
use InstallerToolkit\Update\Models\UpdateHistory;
use InstallerToolkit\Update\UpdateService;

class UpdateRollbackCommand extends Command
{
    protected $signature = 'update:rollback
        {--backup= : The backup id to restore (defaults to the most recent applied update)}
        {--skip-db : Skip restoring the database dump}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Roll back the application to a previous update backup';

    public function handle(UpdateService $service): int
    {
        $backupId = $this->option('backup');

        if ($backupId === null) {
            $backupId = $this->latestBackupId();

            if ($backupId === null) {
                $this->error('No applied update with a backup was found to roll back.');

                return self::FAILURE;
            }

            $this->info("No backup specified; using the most recent: {$backupId}");
        }

        if (! $this->option('force') && ! $this->confirm("Restore backup [{$backupId}]? This will overwrite current files and (unless --skip-db) the database.")) {
            return self::SUCCESS;
        }

        $history = $this->findHistory($backupId);

        try {
            $this->info('Restoring files...');

            $skipDatabase = (bool) $this->option('skip-db');

            if ($skipDatabase) {
                $this->comment('Skipping database restore (--skip-db).');
            }

            $service->restoreBackup($backupId, $skipDatabase);
            $service->clearCaches();

            if ($history instanceof UpdateHistory) {
                $service->markRolledBack($history);
                $service->dispatchRolledBack($history);
            }

            $this->info("Rolled back to backup [{$backupId}] successfully.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Rollback failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function latestBackupId(): ?string
    {
        if (! Schema::hasTable('update_history')) {
            return null;
        }

        /** @var UpdateHistory|null $latest */
        $latest = UpdateHistory::where('status', UpdateHistory::STATUS_APPLIED)
            ->whereNotNull('backup_id')
            ->latest()
            ->first();

        return $latest?->backup_id;
    }

    private function findHistory(string $backupId): ?UpdateHistory
    {
        if (! Schema::hasTable('update_history')) {
            return null;
        }

        /** @var UpdateHistory|null $history */
        $history = UpdateHistory::where('backup_id', $backupId)->latest()->first();

        return $history;
    }
}
