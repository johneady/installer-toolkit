<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\Models\UpdateHistory;

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
