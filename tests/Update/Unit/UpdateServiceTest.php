<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InstallerToolkit\Update\Models\UpdateHistory;
use InstallerToolkit\Update\UpdateBackupManager;
use InstallerToolkit\Update\UpdateService;

beforeEach(function (): void {
    $this->service = app(UpdateService::class);
});

// ===========================================================================
// Validation
// ===========================================================================

it('validates a well-formed update package', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()));

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeTrue()
        ->and($result->version)->toBe('1.2.0')
        ->and($result->currentVersion)->toBe('1.0.0')
        ->and($result->filesCount)->toBeInt();
});

it('rejects a missing file', function (): void {
    $result = $this->service->validateUpdateZip('/nonexistent/package.update');

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('not found');
});

it('rejects a file that is not a valid zip', function (): void {
    $path = $this->pendingPath($this->validUploadId());
    file_put_contents($path, 'not a zip');

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('Unable to open');
});

it('rejects a package missing manifest.json', function (): void {
    $path = $this->pendingPath($this->validUploadId());
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('wrong.txt', 'no manifest');
    $zip->close();

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('manifest.json');
});

it('rejects a malformed manifest', function (): void {
    $path = $this->pendingPath($this->validUploadId());
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', '{not json');
    $zip->close();

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('malformed');
});

it('rejects a package with the wrong type', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], ['type' => 'installer']);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('not an update package');
});

it('rejects an invalid version format', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], ['version' => '1.2']);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('Invalid version format');
});

it('rejects a version that is not newer than current', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], ['version' => '1.0.0']);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('must be newer');
});

it('rejects when current version is below the minimum', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], [
        'version' => '2.0.0',
        'minimum_version' => '5.0.0',
    ]);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('requires version');
});

it('rejects when the PHP version is too low', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], [
        'version' => '2.0.0',
        'minimum_php' => '99.0.0',
    ]);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('PHP');
});

it('rejects a package whose inner zip name does not match the slug', function (): void {
    config(['updates.slug' => 'different-slug']);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()));

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('missing different-slug.zip');
});

it('rejects a package with a bad checksum', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), [], ['checksum' => str_repeat('a', 64)]);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('integrity check failed');
});

it('rejects a package whose inner zip contains path traversal', function (): void {
    $path = $this->buildUpdatePackageWithRawInner(
        $this->pendingPath($this->validUploadId()),
        function (string $innerPath): void {
            $zip = new ZipArchive;
            $zip->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString($this->testSlug.'/artisan', '<?php // ok');
            $zip->addFromString($this->testSlug.'/../evil.txt', 'escaped');
            $zip->close();
        }
    );

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('invalid file paths');
});

// ===========================================================================
// Staging & extraction
// ===========================================================================

it('stages the inner zip into a scoped staging directory', function (): void {
    $uploadId = $this->validUploadId();
    $path = $this->buildUpdatePackage($this->pendingPath($uploadId));

    $this->service->stageUpdate($path, $uploadId);

    expect(file_exists(storage_path("app/update-staging-{$uploadId}/testapp.zip")))->toBeTrue();
});

it('extracts files stripping the slug prefix and writing to the target path', function (): void {
    $uploadId = $this->validUploadId();
    $target = sys_get_temp_dir().'/extract-'.uniqid();
    $path = $this->buildUpdatePackage($this->pendingPath($uploadId), [
        'artisan' => '<?php // app',
        'config/app.php' => "<?php\nreturn ['version' => '1.2.0'];\n",
        'README.md' => '# readme',
    ]);

    $this->service->stageUpdate($path, $uploadId);
    $result = $this->service->extractBatch($uploadId, 500, $target);

    expect($result['done'])->toBeTrue()
        ->and(file_exists($target.'/artisan'))->toBeTrue()
        ->and(file_exists($target.'/config/app.php'))->toBeTrue()
        ->and(file_get_contents($target.'/README.md'))->toBe('# readme');

    File::deleteDirectory($target);
});

it('extracts new migration files but never sqlite data files', function (): void {
    $uploadId = $this->validUploadId();
    $target = sys_get_temp_dir().'/extract-'.uniqid();
    $path = $this->buildUpdatePackage($this->pendingPath($uploadId), [
        'database/migrations/2026_01_01_000000_add_new_table.php' => '<?php // migration',
        'database/seeders/NewSeeder.php' => '<?php // seeder',
        'database/database.sqlite' => 'live data',
    ]);

    $this->service->stageUpdate($path, $uploadId);
    $this->service->extractBatch($uploadId, 500, $target);

    expect(file_exists($target.'/database/migrations/2026_01_01_000000_add_new_table.php'))->toBeTrue()
        ->and(file_exists($target.'/database/seeders/NewSeeder.php'))->toBeTrue()
        ->and(file_exists($target.'/database/database.sqlite'))->toBeFalse();

    File::deleteDirectory($target);
});

it('skips protected paths during extraction', function (): void {
    $uploadId = $this->validUploadId();
    $target = sys_get_temp_dir().'/extract-'.uniqid();
    $path = $this->buildUpdatePackage($this->pendingPath($uploadId), [
        '.env' => 'DB_HOST=evil',
        'storage/app/license.key' => 'stolen-key',
        'app/keep-me.php' => '<?php // keep',
    ]);

    $this->service->stageUpdate($path, $uploadId);
    $this->service->extractBatch($uploadId, 500, $target);

    expect(file_exists($target.'/.env'))->toBeFalse()
        ->and(file_exists($target.'/storage/app/license.key'))->toBeFalse()
        ->and(file_exists($target.'/app/keep-me.php'))->toBeTrue();

    File::deleteDirectory($target);
});

it('extracts in batches and reports completion across multiple calls', function (): void {
    $uploadId = $this->validUploadId();
    $target = sys_get_temp_dir().'/extract-'.uniqid();

    $files = [];
    for ($i = 0; $i < 12; $i++) {
        $files["batch/file_{$i}.txt"] = "content {$i}";
    }

    $path = $this->buildUpdatePackage($this->pendingPath($uploadId), $files);

    $this->service->stageUpdate($path, $uploadId);

    $first = $this->service->extractBatch($uploadId, 5, $target);
    expect($first['done'])->toBeFalse()
        ->and($first['extracted'])->toBe(5);

    $second = $this->service->extractBatch($uploadId, 5, $target);
    expect($second['extracted'])->toBe(10);

    $third = $this->service->extractBatch($uploadId, 5, $target);
    expect($third['done'])->toBeTrue()
        ->and($third['extracted'])->toBe(12);

    File::deleteDirectory($target);
});

it('rejects an invalid upload identifier for scoped paths', function (): void {
    expect(fn () => $this->service->pendingUpdatePath('../traversal'))
        ->toThrow(InvalidArgumentException::class);
});

it('cleans up staging, progress, and the uploaded zip', function (): void {
    $uploadId = $this->validUploadId();
    $path = $this->buildUpdatePackage($this->pendingPath($uploadId));

    $this->service->stageUpdate($path, $uploadId);

    expect(file_exists(storage_path("app/update-staging-{$uploadId}")))->toBeTrue();

    $this->service->cleanup($uploadId, $path);

    expect(file_exists(storage_path("app/update-staging-{$uploadId}")))->toBeFalse()
        ->and(file_exists($path))->toBeFalse();
});

// ===========================================================================
// Migrations, caches, optimize, verify
// ===========================================================================

it('runs migrations, clears caches, and optimizes without throwing', function (): void {
    $this->service->runMigrations();
    $this->service->clearCaches();
    $this->service->optimize();

    expect(true)->toBeTrue();
});

it('verifies the active version via the config source', function (): void {
    config(['app.version' => '1.2.0']);

    expect($this->service->verify('1.2.0'))->toBeTrue()
        ->and($this->service->verify('9.9.9'))->toBeFalse();
});

it('reads the manifest from an update package', function (): void {
    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()));

    $manifest = $this->service->readManifest($path);

    expect($manifest)->not->toBeNull()
        ->and($manifest['type'])->toBe('update')
        ->and($manifest['version'])->toBe('1.2.0');
});

// ===========================================================================
// History (graceful)
// ===========================================================================

it('records update history through its lifecycle', function (): void {
    $history = $this->service->startHistory('1.0.0', '1.2.0', 'backup-123', ['version' => '1.2.0']);

    expect($history)->not->toBeNull()
        ->and($history->status)->toBe(UpdateHistory::STATUS_IN_PROGRESS)
        ->and($history->backup_id)->toBe('backup-123');

    $this->service->completeHistory($history);
    expect($history->fresh()->status)->toBe(UpdateHistory::STATUS_APPLIED);

    $failed = $this->service->startHistory('1.0.0', '1.2.0');
    $this->service->failHistory($failed, 'boom');
    expect($failed->fresh()->status)->toBe(UpdateHistory::STATUS_FAILED)
        ->and($failed->fresh()->error)->toBe('boom');
});

it('degrades gracefully when the history table is absent', function (): void {
    Schema::dropIfExists('update_history');

    expect($this->service->startHistory('1.0.0', '1.2.0'))->toBeNull();
});

// ===========================================================================
// Backup
// ===========================================================================

it('creates a backup with files and metadata', function (): void {
    $this->minimalBackupScope();

    $id = $this->service->createBackup();
    $dir = app(UpdateBackupManager::class)->directory($id);

    expect($id)->toBeString()
        ->and(file_exists($dir.'/files.zip'))->toBeTrue()
        ->and(file_exists($dir.'/backup.json'))->toBeTrue();
});

it('restores files from a backup', function (): void {
    $this->minimalBackupScope();

    $marker = base_path('backup_marker_'.uniqid().'.txt');
    file_put_contents($marker, 'before');

    $id = $this->service->createBackup();

    @unlink($marker);
    expect(file_exists($marker))->toBeFalse();

    $this->service->restoreBackup($id);

    expect(file_exists($marker))->toBeTrue()
        ->and(file_get_contents($marker))->toBe('before');

    @unlink($marker);
});

it('keeps only the configured number of backups when pruning', function (): void {
    config(['updates.backup.include_vendor' => false]);
    config(['updates.backup.directory' => 'update-backups-prune-'.uniqid()]);

    $manager = app(UpdateBackupManager::class);
    $root = $manager->rootDirectory();
    File::ensureDirectoryExists($root);

    foreach (range(1, 4) as $i) {
        $dir = $root.'/2024010'.(10 - $i).'00000-aaaa'.$i;
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/files.zip', 'x');
        touch($dir, time() - ($i * 1000));
    }

    $pruned = $this->service->pruneBackups(2);

    expect($pruned)->toBe(2)
        ->and(glob($root.'/*'))->toHaveCount(2);

    File::deleteDirectory($root);
});

it('rolls back to a backup and marks the history record as rolled back', function (): void {
    $this->minimalBackupScope();
    config(['updates.backup.directory' => 'rollback-test-'.uniqid()]);

    $marker = base_path('rollback_marker_'.uniqid().'.txt');
    file_put_contents($marker, 'original');

    $backupId = $this->service->createBackup();

    $history = UpdateHistory::create([
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
        'backup_id' => $backupId,
        'status' => UpdateHistory::STATUS_APPLIED,
    ]);

    file_put_contents($marker, 'tampered');

    $this->service->rollback($backupId);

    expect(file_get_contents($marker))->toBe('original');

    $history->refresh();

    expect($history->status)->toBe(UpdateHistory::STATUS_ROLLED_BACK);

    @unlink($marker);
    File::deleteDirectory(app(UpdateBackupManager::class)->rootDirectory());
});

it('reports whether a backup exists on disk', function (): void {
    config(['updates.backup.directory' => 'exists-test-'.uniqid()]);

    expect($this->service->backupExists(null))->toBeFalse()
        ->and($this->service->backupExists('missing'))->toBeFalse();
});

it('lists backups via the backup manager', function (): void {
    config(['updates.backup.directory' => 'list-test-'.uniqid()]);

    expect($this->service->listBackups())->toBe([]);
});
