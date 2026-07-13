<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\RecentUpdateResults;
use InstallerToolkit\Update\UpdateResult;

function resultsDir(): string
{
    return base_path((string) config('updates.updater.storage_dir', 'storage/app/updater').'/results');
}

beforeEach(function (): void {
    if (is_dir(resultsDir())) {
        File::deleteDirectory(resultsDir());
    }
});

function writeResult(string $name, array $data): void
{
    File::ensureDirectoryExists(resultsDir());

    file_put_contents(resultsDir().'/'.$name, json_encode($data, JSON_PRETTY_PRINT));
}

it('returns an empty list when the results directory does not exist', function (): void {
    expect(app(RecentUpdateResults::class)->take())->toHaveCount(0);
});

it('reads results newest first and parses known fields', function (): void {
    writeResult('20260710-100000-applied.json', [
        'status' => 'applied',
        'finished_at' => '2026-07-10T10:00:00-04:00',
        'updater_version' => '2.4.0',
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
        'backup_id' => '20260710-100000-abc',
    ]);

    writeResult('20260712-080000-rolled_back.json', [
        'status' => 'rolled_back',
        'finished_at' => '2026-07-12T08:00:00-04:00',
        'updater_version' => '2.4.0',
        'version_from' => '1.1.0',
        'version_to' => '1.2.0',
        'backup_id' => '20260712-080000-def',
        'failed_task' => 'verify',
        'error' => 'version mismatch',
    ]);

    $results = app(RecentUpdateResults::class)->take();

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(UpdateResult::class)
        // Filenames embed Ymd-His, so newest (2026-07-12) sorts first.
        ->and($results[0]->status)->toBe('rolled_back')
        ->and($results[0]->rolledBack())->toBeTrue()
        ->and($results[0]->failedTask)->toBe('verify')
        ->and($results[0]->error)->toBe('version mismatch')
        ->and($results[1]->status)->toBe('applied')
        ->and($results[1]->applied())->toBeTrue()
        ->and($results[1]->versionFrom)->toBe('1.0.0')
        ->and($results[1]->versionTo)->toBe('1.1.0');
});

it('skips malformed result files without aborting', function (): void {
    File::ensureDirectoryExists(resultsDir());

    file_put_contents(resultsDir().'/20260712-090000-applied.json', '{this is not json');
    writeResult('20260712-080000-applied.json', ['status' => 'applied']);

    $results = app(RecentUpdateResults::class)->take();

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe('applied');
});

it('respects the limit, keeping the newest entries', function (): void {
    foreach (range(1, 4) as $i) {
        writeResult(sprintf('2026071%d-100000-applied.json', 2 + $i), ['status' => 'applied']);
    }

    $results = app(RecentUpdateResults::class)->take(2);

    expect($results)->toHaveCount(2)
        ->and($results[0]->file)->toBe('20260716-100000-applied.json')
        ->and($results[1]->file)->toBe('20260715-100000-applied.json');
});

// Drift guard: these payloads mirror the EXACT field sets the framework-free
// updater writes via RunsUpdateTasks::writeResult() (see
// templates/update/Concerns/RunsUpdateTasks.php — the 'applied' caller near
// line 336 and the 'rolled_back' caller near line 447). If a field is renamed
// or added there, update it here too: every field the writer emits must
// surface on UpdateResult (started_at is intentionally not surfaced).
it('round-trips the updater writer applied payload without field loss', function (): void {
    writeResult('20260712-100000-applied.json', [
        'status' => 'applied',
        'finished_at' => date('c'),
        'updater_version' => '2.4.0',
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
        'backup_id' => '20260712-100000-abc',
        'started_at' => '2026-07-12T09:59:00-04:00',
    ]);

    $result = app(RecentUpdateResults::class)->take()[0];

    expect($result->status)->toBe('applied')
        ->and($result->updaterVersion)->toBe('2.4.0')
        ->and($result->versionFrom)->toBe('1.0.0')
        ->and($result->versionTo)->toBe('1.1.0')
        ->and($result->backupId)->toBe('20260712-100000-abc')
        ->and($result->finishedAt)->not->toBeNull()
        ->and($result->applied())->toBeTrue();
});

it('round-trips the updater writer rolled_back payload without field loss', function (): void {
    writeResult('20260712-100000-rolled_back.json', [
        'status' => 'rolled_back',
        'finished_at' => date('c'),
        'updater_version' => '2.4.0',
        'version_from' => '1.1.0',
        'version_to' => '1.2.0',
        'backup_id' => '20260712-100000-def',
        'failed_task' => 'verify',
        'error' => 'version mismatch',
    ]);

    $result = app(RecentUpdateResults::class)->take()[0];

    expect($result->status)->toBe('rolled_back')
        ->and($result->failedTask)->toBe('verify')
        ->and($result->error)->toBe('version mismatch')
        ->and($result->backupId)->toBe('20260712-100000-def')
        ->and($result->rolledBack())->toBeTrue();
});

it('returns null for the oldest result when no results exist', function (): void {
    expect(app(RecentUpdateResults::class)->oldest())->toBeNull();
});

it('infers the originally installed version from the oldest result', function (): void {
    writeResult('20260710-100000-applied.json', [
        'status' => 'applied',
        'finished_at' => '2026-07-10T10:00:00-04:00',
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
    ]);

    writeResult('20260712-080000-applied.json', [
        'status' => 'applied',
        'finished_at' => '2026-07-12T08:00:00-04:00',
        'version_from' => '1.1.0',
        'version_to' => '1.2.0',
    ]);

    $oldest = app(RecentUpdateResults::class)->oldest();

    expect($oldest)->toBeInstanceOf(UpdateResult::class)
        ->and($oldest->versionFrom)->toBe('1.0.0')
        ->and($oldest->versionTo)->toBe('1.1.0');
});

it('skips corrupt files when finding the oldest result', function (): void {
    File::ensureDirectoryExists(resultsDir());
    file_put_contents(resultsDir().'/20260709-090000-applied.json', '{not json');

    writeResult('20260710-100000-applied.json', [
        'status' => 'applied',
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
    ]);

    $oldest = app(RecentUpdateResults::class)->oldest();

    expect($oldest)->not->toBeNull()
        ->and($oldest->versionFrom)->toBe('1.0.0');
});

it('skips a version-less oldest result and returns the next oldest', function (): void {
    // Oldest run failed before capturing a version_from.
    writeResult('20260709-090000-failed.json', [
        'status' => 'failed',
        'finished_at' => '2026-07-09T09:00:00-04:00',
    ]);

    writeResult('20260710-100000-applied.json', [
        'status' => 'applied',
        'finished_at' => '2026-07-10T10:00:00-04:00',
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
    ]);

    $oldest = app(RecentUpdateResults::class)->oldest();

    expect($oldest)->not->toBeNull()
        ->and($oldest->versionFrom)->toBe('1.0.0')
        ->and($oldest->file)->toBe('20260710-100000-applied.json');
});

it('returns null for the oldest result when none record a versionFrom', function (): void {
    writeResult('20260710-100000-failed.json', ['status' => 'failed']);
    writeResult('20260712-080000-failed.json', ['status' => 'failed']);

    expect(app(RecentUpdateResults::class)->oldest())->toBeNull();
});
