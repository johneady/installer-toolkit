<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Create the updater's storage dir in the testbench skeleton and return it.
 * The directory is not guaranteed to exist there, so each test creates its
 * own target rather than relying on another test's side effect.
 */
function updaterStorageDir(): string
{
    $dir = base_path((string) config('updates.updater.storage_dir', 'storage/app/updater'));
    File::ensureDirectoryExists($dir);

    return $dir;
}

it('prunes stale upload artifacts from the updater storage dir', function (): void {
    $dir = updaterStorageDir();

    $stale = [
        $dir.'/upload-aaaabbbbccccddddeeee000011112222.part',
        $dir.'/pending-aaaabbbbccccddddeeee000011112222.update',
        $dir.'/staged-aaaabbbbccccddddeeee000011112222.zip',
    ];

    foreach ($stale as $file) {
        file_put_contents($file, 'old');
        touch($file, time() - (48 * 3600));
    }

    $fresh = $dir.'/pending-ffffbbbbccccddddeeee000011112222.update';
    file_put_contents($fresh, 'new');

    $this->artisan('update:prune', ['--hours' => 24])
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 3');

    foreach ($stale as $file) {
        expect(file_exists($file))->toBeFalse();
    }

    expect(file_exists($fresh))->toBeTrue();

    @unlink($fresh);
});

it('leaves backups, results, and the handoff token alone', function (): void {
    $dir = updaterStorageDir();
    File::ensureDirectoryExists($dir.'/backups/20260101-000000-abcdef');
    File::ensureDirectoryExists($dir.'/results');

    $kept = [
        $dir.'/backups/20260101-000000-abcdef/files.zip',
        $dir.'/results/20260101-000000-applied.json',
        $dir.'/handoff.json',
        $dir.'/update-in-progress.json',
    ];

    foreach ($kept as $file) {
        file_put_contents($file, 'keep');
        touch($file, time() - (48 * 3600));
    }

    $this->artisan('update:prune', ['--hours' => 24])
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 0');

    foreach ($kept as $file) {
        expect(file_exists($file))->toBeTrue();
        @unlink($file);
    }
});

it('defaults the age threshold to config updates.prune_hours', function (): void {
    config()->set('updates.prune_hours', 1);

    $dir = updaterStorageDir();

    $stale = $dir.'/upload-bbbbbbbbccccddddeeee000011112222.part';
    file_put_contents($stale, 'old');
    touch($stale, time() - (2 * 3600));

    $this->artisan('update:prune')
        ->assertSuccessful()
        ->expectsOutputToContain('older than 1 hour')
        ->expectsOutputToContain('Pruned 1');

    expect(file_exists($stale))->toBeFalse();
});
