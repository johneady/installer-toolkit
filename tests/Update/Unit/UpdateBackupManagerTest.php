<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\UpdateBackupManager;
use InstallerToolkit\Update\UpdateService;

beforeEach(function (): void {
    $this->manager = app(UpdateBackupManager::class);
});

/**
 * Build a minimal exclude list that keeps the backup fast while still covering
 * the directory-prefix cases that previously regressed.
 *
 * @return list<string>
 */
function regressionExclude(): array
{
    return [
        'storage/framework',
        'storage/logs',
        'storage/app/update-backups',
        'storage/app/update-staging-*',
        'storage/app/pending-update-*',
        'app', 'config', 'public', 'routes', 'bootstrap', 'resources',
        'database', 'lang', 'tests', 'package', 'docs', 'bin',
        'vendor', 'node_modules', '.git', '.idea',
    ];
}

it('excludes nested directory patterns, prior backups, and wildcards', function (): void {
    $method = new ReflectionMethod($this->manager, 'isExcluded');
    $method->setAccessible(true);

    $exclude = regressionExclude();

    $excluded = [
        'storage/framework/cache/data/abc',
        'storage/logs/laravel.log',
        'storage/app/update-backups/20240101000000-aaaa/files.zip',
        'storage/app/update-staging-deadbeef/testapp.zip',
        'node_modules/foo/bar.js',
        'package/anything',
        'vendor/autoload.php',
    ];

    $kept = [
        'artisan',
        'storage/app/keep-me.txt',
        'storage/app/public/upload.png',
    ];

    foreach ($excluded as $path) {
        expect($method->invoke($this->manager, $path, $exclude))
            ->toBeTrue("Expected [{$path}] to be excluded.");
    }

    foreach ($kept as $path) {
        expect($method->invoke($this->manager, $path, $exclude))
            ->toBeFalse("Expected [{$path}] to be kept.");
    }
});

it('does not back up cache files or previous backups', function (): void {
    $backupDir = 'update-backups-cache-'.uniqid();
    config(['updates.backup.directory' => $backupDir]);
    config(['updates.backup.include_vendor' => false]);
    config(['updates.backup.exclude' => [...regressionExclude(), "storage/app/{$backupDir}"]]);

    $suffix = uniqid();
    $marker = base_path('regression_marker_'.$suffix.'.txt');
    $cacheFile = base_path('storage/framework/cache/regression_'.$suffix.'.txt');
    $priorBackup = base_path("storage/app/{$backupDir}/prior/files.zip");

    file_put_contents($marker, 'root');
    File::ensureDirectoryExists(dirname($cacheFile));
    file_put_contents($cacheFile, 'cache');
    File::ensureDirectoryExists(dirname($priorBackup));
    file_put_contents($priorBackup, 'previous-backup-bytes');

    $id = $this->manager->create();
    $zipPath = $this->manager->directory($id).'/files.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath);

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($entries)->toContain(basename($marker))
        ->and($entries)->not->toContain('storage/framework/cache/regression_'.$suffix.'.txt')
        ->and($entries)->not->toContain("storage/app/{$backupDir}/prior/files.zip");

    @unlink($marker);
    @unlink($cacheFile);
    File::deleteDirectory($this->manager->rootDirectory());
});

it('deletes files added after the backup when restoring', function (): void {
    config(['updates.backup.directory' => 'update-backups-restore-'.uniqid()]);
    config(['updates.backup.include_vendor' => false]);
    config(['updates.backup.exclude' => regressionExclude()]);

    $suffix = uniqid();
    $kept = base_path("restore_kept_{$suffix}.txt");
    file_put_contents($kept, 'before');

    $id = $this->manager->create();

    // Simulate a failed update: one existing file changed, one new file
    // added, and one new file in an excluded (never-backed-up) area.
    $added = base_path("restore_added_{$suffix}.txt");
    $excludedAdded = base_path('storage/framework/added_'.$suffix.'.txt');
    file_put_contents($kept, 'tampered');
    file_put_contents($added, 'added by update');
    File::ensureDirectoryExists(dirname($excludedAdded));
    file_put_contents($excludedAdded, 'outside backup scope');

    $this->manager->restore($id);

    expect(file_get_contents($kept))->toBe('before')
        ->and(file_exists($added))->toBeFalse()
        ->and(file_exists($excludedAdded))->toBeTrue();

    @unlink($kept);
    @unlink($excludedAdded);
    File::deleteDirectory($this->manager->rootDirectory());
});

it('counts every backup when several share the same mtime during pruning', function (): void {
    config(['updates.backup.directory' => 'update-backups-mtime-'.uniqid()]);

    $root = $this->manager->rootDirectory();
    File::ensureDirectoryExists($root);

    $sharedMtime = time() - 5000;
    foreach (range(1, 4) as $i) {
        $dir = $root.'/2024010100000'.$i.'-cc'.$i;
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/files.zip', 'x');
        touch($dir, $sharedMtime);
    }

    $pruned = app(UpdateService::class)->pruneBackups(2);

    expect($pruned)->toBe(2)
        ->and(glob($root.'/*'))->toHaveCount(2);

    File::deleteDirectory($root);
});

it('lists backups sorted newest first with metadata', function (): void {
    config(['updates.backup.directory' => 'update-backups-list-'.uniqid()]);

    $root = $this->manager->rootDirectory();
    File::ensureDirectoryExists($root);

    foreach (range(1, 3) as $i) {
        $dir = $root.'/2024010'.$i.'000000-id'.$i;
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/files.zip', 'x');
        file_put_contents($dir.'/backup.json', json_encode([
            'id' => '2024010'.$i.'000000-id'.$i,
            'version' => '1.'.$i.'.0',
            'created_at' => '2024-01-0'.$i.'T00:00:00+00:00',
        ]));

        if ($i === 1) {
            file_put_contents($dir.'/database.sql', '-- dump');
        }
    }

    $backups = $this->manager->listBackups();

    expect($backups)->toHaveCount(3)
        ->and($backups[0]['id'])->toBe('20240103000000-id3')
        ->and($backups[2]['id'])->toBe('20240101000000-id1')
        ->and($backups[2]['version'])->toBe('1.1.0')
        ->and($backups[2]['has_database'])->toBeTrue()
        ->and($backups[1]['has_database'])->toBeFalse();

    File::deleteDirectory($root);
});

it('returns an empty array when no backups exist', function (): void {
    config(['updates.backup.directory' => 'update-backups-empty-'.uniqid()]);

    expect($this->manager->listBackups())->toBe([]);
});

it('checks whether a backup exists on disk', function (): void {
    config(['updates.backup.directory' => 'update-backups-exists-'.uniqid()]);

    $root = $this->manager->rootDirectory();
    File::ensureDirectoryExists($root.'/existing-id');
    file_put_contents($root.'/existing-id/files.zip', 'x');

    expect($this->manager->exists('existing-id'))->toBeTrue()
        ->and($this->manager->exists('missing-id'))->toBeFalse();

    File::deleteDirectory($root);
});

it('creates a backup record id and prunes to the configured limit', function (): void {
    config(['updates.backup.directory' => 'update-backups-limit-'.uniqid()]);

    $root = $this->manager->rootDirectory();
    File::ensureDirectoryExists($root);

    foreach (range(1, 3) as $i) {
        $dir = $root.'/2024010'.(10 - $i).'00000-bb'.$i;
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/files.zip', 'x');
        touch($dir, time() - ($i * 1000));
    }

    $pruned = app(UpdateService::class)->pruneBackups(1);

    expect($pruned)->toBe(2)
        ->and(glob($root.'/*'))->toHaveCount(1);

    File::deleteDirectory($root);
});
