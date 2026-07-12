<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

/**
 * One entry from the standalone updater's results log
 * (storage/app/updater/results/*.json).
 *
 * The updater is framework-free and the system of record for update outcomes.
 * This DTO normalizes a single run so the admin panel can render recent
 * results without a database or an ingestion step.
 */
final class UpdateResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $finishedAt = null,
        public readonly ?string $updaterVersion = null,
        public readonly ?string $versionFrom = null,
        public readonly ?string $versionTo = null,
        public readonly ?string $backupId = null,
        public readonly ?string $failedTask = null,
        public readonly ?string $error = null,
        public readonly ?string $file = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $file = null): self
    {
        return new self(
            status: (string) ($data['status'] ?? 'unknown'),
            finishedAt: isset($data['finished_at']) ? (string) $data['finished_at'] : null,
            updaterVersion: isset($data['updater_version']) ? (string) $data['updater_version'] : null,
            versionFrom: isset($data['version_from']) ? (string) $data['version_from'] : null,
            versionTo: isset($data['version_to']) ? (string) $data['version_to'] : null,
            backupId: isset($data['backup_id']) ? (string) $data['backup_id'] : null,
            failedTask: isset($data['failed_task']) ? (string) $data['failed_task'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            file: $file,
        );
    }

    /**
     * Whether the updater applied the package successfully.
     */
    public function applied(): bool
    {
        return $this->status === 'applied';
    }

    /**
     * Whether the updater rolled the application back after a failed run.
     */
    public function rolledBack(): bool
    {
        return $this->status === 'rolled_back';
    }
}
