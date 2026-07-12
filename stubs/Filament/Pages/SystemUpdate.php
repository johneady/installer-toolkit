<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use InstallerToolkit\Update\Models\UpdateHistory;
use InstallerToolkit\Update\UpdateService;
use InstallerToolkit\Update\UpdateValidationResult;

/**
 * Self-update page driven by johneady/installer-toolkit.
 *
 * This file is a published stub — own and adjust it for your panel, auth, and
 * Filament version (the engine lives in InstallerToolkit\Update\UpdateService).
 */
class SystemUpdate extends Page
{
    protected static ?string $panel = 'server';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'System Update';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.system-update';

    #[Locked]
    public ?string $uploadId = null;

    public ?string $backupId = null;

    #[Locked]
    public ?int $historyId = null;

    public ?UpdateValidationResult $validationResult = null;

    public bool $isUpdating = false;

    public bool $updateComplete = false;

    public ?string $errorMessage = null;

    public ?string $updateVersion = null;

    public bool $isUploading = false;

    public int $uploadProgress = 0;

    /**
     * @var array<int, array{key: string, label: string, status: string, detail: string}>
     */
    public array $steps = [];

    public int $currentStepIndex = 0;

    public int $extractedFiles = 0;

    public int $totalFiles = 0;

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()->getId() === 'server';
    }

    public function getTitle(): string
    {
        return 'System Update';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label('Update History')
                ->icon('heroicon-o-clock')
                ->url(fn () => \App\Filament\Pages\UpdateHistory::getUrl()),
        ];
    }

    public function mount(): void
    {
        $this->resetState();
    }

    /**
     * Resolve the toolkit update service.
     */
    protected function service(): UpdateService
    {
        return app(UpdateService::class);
    }

    public function validateUpload(): void
    {
        $this->errorMessage = null;
        $this->updateComplete = false;

        $service = $this->service();

        if (! $this->uploadId) {
            $this->errorMessage = 'No uploaded file found. Please upload an update package first.';

            return;
        }

        $pendingPath = $service->pendingUpdatePath($this->uploadId);

        if (! file_exists($pendingPath)) {
            $this->errorMessage = 'No uploaded file found. Please upload an update package first.';

            return;
        }

        $result = $service->validateUpdateZip($pendingPath);
        $this->validationResult = $result;

        if (! $result->valid) {
            $this->errorMessage = $result->error;
            @unlink($pendingPath);

            return;
        }

        $this->updateVersion = $result->version;
    }

    public function startUpdate(): void
    {
        if ($this->isUpdating || ! $this->uploadId || ! $this->validationResult?->valid) {
            return;
        }

        $this->isUpdating = true;
        $this->errorMessage = null;
        $this->currentStepIndex = 0;

        $this->steps = [
            ['key' => 'backup', 'label' => 'Creating backup', 'status' => 'pending', 'detail' => ''],
            ['key' => 'stage', 'label' => 'Staging update files', 'status' => 'pending', 'detail' => ''],
            ['key' => 'extract', 'label' => 'Extracting files', 'status' => 'pending', 'detail' => ''],
            ['key' => 'migrate', 'label' => 'Running database migrations', 'status' => 'pending', 'detail' => ''],
            ['key' => 'clear_cache', 'label' => 'Clearing caches', 'status' => 'pending', 'detail' => ''],
            ['key' => 'verify', 'label' => 'Verifying update', 'status' => 'pending', 'detail' => ''],
            ['key' => 'optimize', 'label' => 'Optimizing application', 'status' => 'pending', 'detail' => ''],
        ];

        $this->runNextStep();
    }

    public function runNextStep(): void
    {
        if (! $this->isUpdating || $this->currentStepIndex >= count($this->steps)) {
            return;
        }

        $step = $this->steps[$this->currentStepIndex];
        $this->steps[$this->currentStepIndex]['status'] = 'running';

        try {
            $service = $this->service();
            $zipPath = $service->pendingUpdatePath($this->uploadId);

            match ($step['key']) {
                'backup' => $this->stepBackup($service, $zipPath),
                'stage' => $service->stageUpdate($zipPath, $this->uploadId),
                'extract' => $this->stepExtract($service),
                'migrate' => $this->stepMigrate($service),
                'clear_cache' => $service->clearCaches(),
                'verify' => $this->stepVerify($service, $zipPath),
                'optimize' => $this->stepOptimize($service),
                default => null,
            };

            if ($step['key'] === 'extract' && ! $this->isExtractionComplete()) {
                return;
            }

            $this->steps[$this->currentStepIndex]['status'] = 'complete';
            $this->currentStepIndex++;

            if ($this->currentStepIndex >= count($this->steps)) {
                $this->isUpdating = false;
                $this->updateComplete = true;

                $history = $this->resolveHistory();
                if ($history) {
                    $service->completeHistory($history);
                    $service->dispatchCompleted($history);
                }

                $service->pruneBackups((int) config('updates.backup.keep', 3));

                Notification::make()
                    ->success()
                    ->title('Update Complete')
                    ->body("Successfully updated to v{$this->updateVersion}.")
                    ->send();

                return;
            }

            $this->dispatch('continue-update');
        } catch (\Throwable $e) {
            $this->handleFailure($e, $step['label']);
        }
    }

    public function continueExtraction(): void
    {
        if (! $this->isUpdating) {
            return;
        }

        try {
            $service = $this->service();
            $result = $service->extractBatch($this->uploadId, (int) config('updates.extraction_batch_size', 500));

            $this->extractedFiles = $result['extracted'];
            $this->totalFiles = $result['total'];
            $this->steps[$this->currentStepIndex]['detail'] = "{$this->extractedFiles}/{$this->totalFiles} files";

            if ($result['done']) {
                $this->steps[$this->currentStepIndex]['status'] = 'complete';
                $this->currentStepIndex++;
                $this->dispatch('continue-update');
            } else {
                $this->dispatch('continue-extraction');
            }
        } catch (\Throwable $e) {
            $this->handleFailure($e, 'Extraction');
        }
    }

    public function uploadComplete(string $uploadId): void
    {
        $this->uploadId = $uploadId;
        $this->isUploading = false;
        $this->uploadProgress = 100;
        $this->validateUpload();
    }

    public function uploadFailed(string $message): void
    {
        $this->isUploading = false;
        $this->uploadProgress = 0;
        $this->errorMessage = $message;
    }

    public function resetState(): void
    {
        $this->uploadId = null;
        $this->backupId = null;
        $this->historyId = null;
        $this->validationResult = null;
        $this->isUpdating = false;
        $this->updateComplete = false;
        $this->errorMessage = null;
        $this->updateVersion = null;
        $this->isUploading = false;
        $this->uploadProgress = 0;
        $this->steps = [];
        $this->currentStepIndex = 0;
        $this->extractedFiles = 0;
        $this->totalFiles = 0;
    }

    protected function stepBackup(UpdateService $service, string $zipPath): void
    {
        $versionFrom = (string) config('app.version');
        $backupId = null;

        if ((bool) config('updates.backup.enabled', true)) {
            $backupId = $service->createBackup();
            $this->backupId = $backupId;
            $this->steps[$this->currentStepIndex]['detail'] = 'Backup ready';
        } else {
            $this->steps[$this->currentStepIndex]['detail'] = 'Skipped (disabled)';
        }

        // History and the start event are recorded regardless of whether a
        // backup was made, so the audit log stays complete.
        $manifest = $service->readManifest($zipPath);

        $history = $service->startHistory($versionFrom, (string) $this->updateVersion, $backupId, $manifest);
        $this->historyId = $history?->id;

        $service->dispatchStarted($versionFrom, (string) $this->updateVersion, $backupId);
    }

    protected function stepExtract(UpdateService $service): void
    {
        $result = $service->extractBatch($this->uploadId, (int) config('updates.extraction_batch_size', 500));
        $this->extractedFiles = $result['extracted'];
        $this->totalFiles = $result['total'];
        $this->steps[$this->currentStepIndex]['detail'] = "{$this->extractedFiles}/{$this->totalFiles} files";

        if (! $result['done']) {
            $this->dispatch('continue-extraction');
        }
    }

    protected function stepMigrate(UpdateService $service): void
    {
        $service->runMigrations();
        $this->steps[$this->currentStepIndex]['detail'] = 'Migrations complete';
    }

    protected function stepVerify(UpdateService $service, string $zipPath): void
    {
        if (! $service->verify((string) $this->updateVersion)) {
            throw new \RuntimeException('Version verification failed. The update may not have been applied correctly.');
        }

        $service->cleanup($this->uploadId, $zipPath);
        $this->steps[$this->currentStepIndex]['detail'] = "v{$this->updateVersion} verified";
    }

    protected function stepOptimize(UpdateService $service): void
    {
        $service->optimize();
        $this->steps[$this->currentStepIndex]['detail'] = 'Optimized';
    }

    protected function handleFailure(\Throwable $e, string $label): void
    {
        $this->steps[$this->currentStepIndex]['status'] = 'failed';
        $this->steps[$this->currentStepIndex]['detail'] = $e->getMessage();
        $this->isUpdating = false;

        $service = $this->service();
        $rolledBack = false;

        if ($this->backupId && (bool) config('updates.backup.enabled', true)) {
            try {
                $service->restoreBackup($this->backupId);
                $service->clearCaches();
                $rolledBack = true;
                $this->errorMessage = "Step failed: {$label} — {$e->getMessage()}. Your application was automatically rolled back to its previous state.";
            } catch (\Throwable $restoreError) {
                $this->errorMessage = "Step failed: {$label} — {$e->getMessage()}. Automatic rollback also failed: {$restoreError->getMessage()} Run `php artisan update:rollback --backup={$this->backupId}` to retry.";
            }
        } else {
            $this->errorMessage = "Step failed: {$label} — {$e->getMessage()}";
        }

        // Record the failure (and its error) first; a successful automatic
        // rollback then flips the status to rolled_back while keeping the
        // error text, so the audit trail reflects what actually happened.
        $history = $this->resolveHistory();
        if ($history) {
            $service->failHistory($history, $e->getMessage());

            if ($rolledBack) {
                $service->markRolledBack($history);
            }
        }

        $service->dispatchFailed((string) config('app.version'), (string) $this->updateVersion, $e, $this->backupId);

        if ($rolledBack && $history) {
            $service->dispatchRolledBack($history);
        }

        // Don't leave the staging dir and pending zip around until the
        // prune command gets to them — the update is over either way.
        if ($this->uploadId) {
            try {
                $service->cleanup($this->uploadId, $service->pendingUpdatePath($this->uploadId));
            } catch (\Throwable) {
                // Best-effort; update:prune removes leftovers later.
            }
        }
    }

    protected function resolveHistory(): ?UpdateHistory
    {
        if (! $this->historyId) {
            return null;
        }

        return UpdateHistory::find($this->historyId);
    }

    protected function isExtractionComplete(): bool
    {
        return $this->extractedFiles >= $this->totalFiles && $this->totalFiles > 0;
    }
}
