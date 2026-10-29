<?php

class Updater
{
    private array $errors = [];

    /** @var array<string, mixed>|null Cached, resolved config/updates.php values. */
    private ?array $updatesConfig = null;

    private int $totalSteps = 4;

    private array $stepNames = [
        1 => 'Status',
        2 => 'Review',
        3 => 'Update',
        4 => 'Complete',
    ];

    // Constants the traits read live here, not in the traits: a trait cannot
    // declare constants before PHP 8.2, and one would stop this whole file
    // compiling on PHP 8.0/8.1, so a customer on an old host would get a fatal
    // error instead of the requirements page telling them to upgrade. CI's
    // legacy-php job compiles the generated files on PHP 8.0 to catch this.

    // ─── Shared by ManagesBackups and RunsUpdateTasks ───

    /**
     * Wall-clock budget per batched backup/restore/extract request, in
     * seconds.
     */
    private const BATCH_BUDGET_SECONDS = 8;

    // ─── Used by HandlesUploads ───

    /** Server-side ceiling per chunk; the client sends 1.5 MB chunks. */
    private const MAX_CHUNK_BYTES = 4194304;

    /** Refuse packages over this size outright (corrupt/abusive uploads). */
    private const MAX_PACKAGE_BYTES = 2147483648;

    // ─── Used by ManagesBackups ───

    /** Files added to the backup zip per request. */
    private const BACKUP_BATCH_FILES = 400;

    // ─── Used by RunsUpdateTasks ───

    /**
     * Default/ceiling files-per-batch for extraction, overridable per-app
     * via config/updates.php's `extraction_batch_size` (e.g. lowered on
     * memory-constrained shared hosts). Also shrunk adaptively at runtime —
     * see taskExtract() — when a batch runs close to or over
     * BATCH_BUDGET_SECONDS.
     */
    private const EXTRACT_BATCH_FILES_DEFAULT = 400;

    /** Locks stale after this many seconds are considered abandoned. */
    private const LOCK_STALE_SECONDS = 1800;

    // ─── Used by ValidatesUpdatePackage ───

    private const SIGNATURE_FORMAT_VERSION = 1;

    // [[INSTALLER_TRAITS]]
    // [[/INSTALLER_TRAITS]]
}
