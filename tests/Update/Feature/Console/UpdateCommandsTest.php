<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\Models\UpdateHistory;
use InstallerToolkit\Update\UpdateService;

beforeEach(function (): void {
    config(['updates.backup.include_vendor' => false]);
    $this->minimalBackupScope();
});

it('lists update history', function (): void {
    UpdateHistory::create([
        'version_from' => '1.0.0',
        'version_to' => '1.2.0',
        'status' => UpdateHistory::STATUS_APPLIED,
    ]);

    $this->artisan('update:history')
        ->assertSuccessful()
        ->expectsOutputToContain('1.2.0');
});

it('informs when no history exists', function (): void {
    $this->artisan('update:history')
        ->assertSuccessful()
        ->expectsOutputToContain('No updates have been recorded');
});

it('prunes stale upload artifacts', function (): void {
    $stale = storage_path('app/pending-update-aaaabbbbccccddddeeee000011112222.update');
    // storage/app is not guaranteed to exist in the testbench skeleton, so the
    // test must create its own target directory rather than rely on another
    // test's side effect.
    File::ensureDirectoryExists(dirname($stale));
    file_put_contents($stale, 'old');
    touch($stale, time() - (48 * 3600));

    $this->artisan('update:prune', ['--hours' => 24])
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 1');

    expect(file_exists($stale))->toBeFalse();
});

it('fails when there is no backup to roll back to', function (): void {
    $this->artisan('update:rollback')
        ->assertFailed()
        ->expectsOutputToContain('No applied update');
});

it('rolls back to the latest applied backup with --force', function (): void {
    $service = app(UpdateService::class);

    $marker = base_path('rollback_marker_'.uniqid().'.txt');
    file_put_contents($marker, 'original');

    $backupId = $service->createBackup();

    @unlink($marker);

    UpdateHistory::create([
        'version_from' => '1.0.0',
        'version_to' => '1.2.0',
        'status' => UpdateHistory::STATUS_APPLIED,
        'backup_id' => $backupId,
    ]);

    $this->artisan('update:rollback', ['--force' => true, '--skip-db' => true])
        ->assertSuccessful();

    expect(file_exists($marker))->toBeTrue()
        ->and(file_get_contents($marker))->toBe('original');

    @unlink($marker);
});
