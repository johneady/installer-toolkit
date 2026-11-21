<?php

declare(strict_types=1);

use InstallerToolkit\Update\UpdateResult;

it('formats a valid finishedAt for display', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => '2026-07-10T10:00:00-04:00']);

    expect($result->finishedAtDisplay())->toBe('2026-07-10 10:00:00');
});

it('returns null when finishedAt is absent', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied']);

    expect($result->finishedAtDisplay())->toBeNull();
});

it('returns null instead of throwing when finishedAt is unparseable', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => 'not-a-date']);

    expect($result->finishedAtDisplay())->toBeNull();
});

it('returns null rather than "now" when finishedAt is an empty string', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => '']);

    expect($result->finishedAtDisplay())->toBeNull();
});

it('maps the run\'s database-backup outcome from the results journal', function (): void {
    $result = UpdateResult::fromArray([
        'status' => 'applied',
        'backup_id' => '20260710-100000-ab12cd',
        'backup_database' => 'Skipped (mysqldump failed: boom) — files-only backup.',
    ]);

    expect($result->backupId)->toBe('20260710-100000-ab12cd')
        ->and($result->backupDatabase)->toBe('Skipped (mysqldump failed: boom) — files-only backup.');
});

it('leaves backupDatabase null for journals written before the field existed', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'backup_id' => '20260710-100000-ab12cd']);

    expect($result->backupDatabase)->toBeNull();
});
