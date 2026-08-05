<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Tests\Fixtures\FakePackageBuildCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function fakeCommand(array $config = []): FakePackageBuildCommand
{
    $command = new FakePackageBuildCommand;
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new NullOutput,
    ));

    return $command->withConfig($config);
}

beforeEach(function () {
    writeAppComposerJson();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
    File::delete(base_path('package/package-config.php'));
    File::delete(base_path('composer.json'));
});

test('handle fails when package-config.php is missing', function () {
    File::delete(base_path('package/package-config.php'));

    $this->artisan('package:build')
        ->assertFailed()
        ->expectsOutputToContain('No package-config.php found');
});

test('handle fails when package-config.php is missing a required key', function (string $missingKey) {
    $config = [
        'name' => 'Fake App',
        'slug' => 'fake-app',
    ];
    unset($config[$missingKey]);

    File::ensureDirectoryExists(base_path('package'));
    file_put_contents(base_path('package/package-config.php'), '<?php return '.var_export($config, true).';');

    $this->artisan('package:build')
        ->assertFailed()
        ->expectsOutputToContain("missing required key: '{$missingKey}'");
})->with(['name', 'slug']);

test('shouldExclude filters out standard dev directories', function (string $path) {
    expect(fakeCommand()->callProtected('shouldExclude', $path))->toBeTrue();
})->with([
    '.git',
    '.git/config',
    'node_modules',
    'node_modules/lodash/index.js',
    'vendor',
    'vendor/laravel/framework/src/x.php',
    'tests',
    'tests/Feature/SomeTest.php',
    'package',
    'docs/whatever.md',
    'demos/x.php',
    'reports/x.html',
    '.claude',
    '.github',
    '.vscode',
    '.kilo',
    '.kilo/node_modules/x.js',
    '.migration',
]);

test('shouldExclude allows application source files', function (string $path) {
    expect(fakeCommand()->callProtected('shouldExclude', $path))->toBeFalse();
})->with([
    'app/Models/User.php',
    'config/app.php',
    'database/migrations/0001_01_01_000000_create_users_table.php',
    'resources/views/welcome.blade.php',
    'public/index.php',
]);

test('shouldExclude only allows whitelisted root files', function () {
    $command = fakeCommand();

    expect($command->callProtected('shouldExclude', 'artisan'))->toBeFalse()
        ->and($command->callProtected('shouldExclude', 'composer.json'))->toBeFalse()
        ->and($command->callProtected('shouldExclude', '.env.install'))->toBeFalse()
        ->and($command->callProtected('shouldExclude', 'README.md'))->toBeTrue()
        ->and($command->callProtected('shouldExclude', 'random.txt'))->toBeTrue();
});

test('shouldExclude merges extraExcludeDirs without requiring a full override', function () {
    $command = new class extends FakePackageBuildCommand
    {
        protected array $extraExcludeDirs = ['screenshots', 'marketing_info'];
    };
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new NullOutput,
    ));
    $command->withConfig([]);

    expect($command->callProtected('shouldExclude', 'screenshots/01.jpg'))->toBeTrue()
        ->and($command->callProtected('shouldExclude', 'marketing_info/brochure.pdf'))->toBeTrue()
        // Base-class exclusions still apply — extraExcludeDirs is additive, not a replacement.
        ->and($command->callProtected('shouldExclude', '.kilo/x.js'))->toBeTrue()
        ->and($command->callProtected('shouldExclude', 'app/Models/User.php'))->toBeFalse();
});

test('shouldExclude keeps gitignore and gitkeep in otherwise-excluded storage paths', function () {
    $command = fakeCommand();

    expect($command->callProtected('shouldExclude', 'storage/logs/laravel.log'))->toBeTrue()
        ->and($command->callProtected('shouldExclude', 'storage/logs/.gitignore'))->toBeFalse()
        ->and($command->callProtected('shouldExclude', 'storage/logs/.gitkeep'))->toBeFalse();
});

test('shouldExclude honors includeStorageFiles override', function () {
    $command = new class extends FakePackageBuildCommand
    {
        protected array $includeStorageFiles = ['app/license.key'];
    };
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new NullOutput,
    ));
    $command->withConfig([]);

    expect($command->callProtected('shouldExclude', 'storage/app/license.key'))->toBeFalse()
        ->and($command->callProtected('shouldExclude', 'storage/app/other-file.txt'))->toBeTrue();
});

test('createZip excludes specified files', function () {
    $stagingDir = storage_path('app/test-createzip-'.uniqid());
    $appFolder = 'fake-app';
    $sourceDir = $stagingDir.'/'.$appFolder;

    File::ensureDirectoryExists($sourceDir.'/storage/app');
    file_put_contents($sourceDir.'/artisan', '#!/usr/bin/env php');
    file_put_contents($sourceDir.'/storage/app/license.key', 'test-license-key');

    $command = fakeCommand();

    $fullZipPath = $stagingDir.'/full.zip';
    $command->callProtected('createZip', $stagingDir, $appFolder, $fullZipPath);

    $zip = new ZipArchive;
    $zip->open($fullZipPath);
    $fullEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $fullEntries[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($fullEntries)->toContain('fake-app/storage/app/license.key');

    $demoZipPath = $stagingDir.'/demo.zip';
    $command->callProtected('createZip', $stagingDir, $appFolder, $demoZipPath, ['storage/app/license.key']);

    $zip = new ZipArchive;
    $zip->open($demoZipPath);
    $demoEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $demoEntries[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($demoEntries)->not->toContain('fake-app/storage/app/license.key')
        ->and($demoEntries)->toContain('fake-app/artisan');
});

test('generateManifest creates a valid manifest carrying the resolved min_php_version', function () {
    $stagingDir = storage_path('app/test-manifest-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = fakeCommand(['min_php_version' => '8.2.0']);

    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0');

    expect(file_exists($manifestPath))->toBeTrue();

    $manifest = json_decode(file_get_contents($manifestPath), true);

    expect($manifest)->toHaveKeys(['type', 'version', 'minimum_version', 'minimum_php', 'checksum', 'built_at', 'files_count'])
        ->and($manifest['type'])->toBe('update')
        ->and($manifest['version'])->toBe('1.3.0')
        ->and($manifest['minimum_php'])->toBe('8.2.0')
        ->and($manifest['minimum_version'])->toBe('1.0.0')
        ->and($manifest['checksum'])->toBe(hash_file('sha256', $innerZipPath));
});

test('generateManifest defaults minimum_version to 1.0.0 when package-config omits minimum_update_version', function () {
    $command = fakeCommand([]);

    expect($command->callProtected('resolveMinimumVersion'))->toBe('1.0.0');
});

test('generateManifest uses package-config minimum_update_version when set', function () {
    $command = fakeCommand(['minimum_update_version' => '2.3.0']);

    expect($command->callProtected('resolveMinimumVersion'))->toBe('2.3.0');
});

test('generateManifest fails closed when minimum_update_version is not SemVer', function () {
    $command = fakeCommand(['minimum_update_version' => 'not-semver']);

    expect(fn () => $command->callProtected('resolveMinimumVersion'))
        ->toThrow(RuntimeException::class, "'minimum_update_version' must be SemVer");
});

test('assembleOutput produces only the full zip and update package when demo is not configured', function () {
    $toolkitOutputDir = storage_path('app/test-assemble-toolkit-'.uniqid());
    $stagingDir = storage_path('app/test-assemble-'.uniqid());
    $outputDir = storage_path('app/test-assemble-output-'.uniqid());

    File::ensureDirectoryExists($toolkitOutputDir);
    file_put_contents($toolkitOutputDir.'/install.php', '<?php // fake installer');
    file_put_contents($toolkitOutputDir.'/readme.html', '<html>fake readme</html>');

    File::ensureDirectoryExists($stagingDir);
    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = fakeCommand();
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.2.0');

    // No demo zip: assembleOutput's $demoZipPath is null, updateZipPath equals the full zip.
    $command->callProtected('assembleOutput', $toolkitOutputDir, $innerZipPath, null, $manifestPath, $outputDir, '1.2.0', $innerZipPath);

    expect(file_exists($outputDir.'/packages/fake-app-v1.2.0-full.zip'))->toBeTrue()
        ->and(file_exists($outputDir.'/packages/fake-app-v1.2.0-demo.zip'))->toBeFalse()
        ->and(file_exists($outputDir.'/packages/fake-app-v1.2.0.update'))->toBeTrue();
});

test('assembleOutput produces a demo zip when a demo zip path is given', function () {
    $toolkitOutputDir = storage_path('app/test-assemble-demo-toolkit-'.uniqid());
    $stagingDir = storage_path('app/test-assemble-demo-'.uniqid());
    $outputDir = storage_path('app/test-assemble-demo-output-'.uniqid());

    File::ensureDirectoryExists($toolkitOutputDir);
    file_put_contents($toolkitOutputDir.'/install.php', '<?php // fake installer');
    file_put_contents($toolkitOutputDir.'/readme.html', '<html>fake readme</html>');

    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $demoZipPath = $stagingDir.'/fake-app-demo.zip';
    $demoZip = new ZipArchive;
    $demoZip->open($demoZipPath, ZipArchive::CREATE);
    $demoZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $demoZip->close();

    $command = fakeCommand();
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $demoZipPath, '1.2.0');

    $command->callProtected('assembleOutput', $toolkitOutputDir, $innerZipPath, $demoZipPath, $manifestPath, $outputDir, '1.2.0', $demoZipPath);

    expect(file_exists($outputDir.'/packages/fake-app-v1.2.0-full.zip'))->toBeTrue()
        ->and(file_exists($outputDir.'/packages/fake-app-v1.2.0-demo.zip'))->toBeTrue()
        ->and(file_exists($outputDir.'/packages/fake-app-v1.2.0.update'))->toBeTrue();
});

test('formatBytes returns human readable sizes', function () {
    $command = fakeCommand();

    expect($command->callProtected('formatBytes', 512))->toBe('512.00 B')
        ->and($command->callProtected('formatBytes', 1024))->toBe('1.00 KB')
        ->and($command->callProtected('formatBytes', 1048576))->toBe('1.00 MB')
        ->and($command->callProtected('formatBytes', 1073741824))->toBe('1.00 GB');
});

test('copyProjectFiles skips the staging directory to prevent recursive copying', function () {
    $source = storage_path('app/test-copy-source-'.uniqid());
    $stagingDir = $source.'/storage/app/package-build-xyz';

    File::ensureDirectoryExists($source.'/app');
    File::ensureDirectoryExists($stagingDir);
    file_put_contents($source.'/app/Foo.php', '<?php');
    file_put_contents($stagingDir.'/should-not-be-copied.txt', 'x');

    $destination = storage_path('app/test-copy-dest-'.uniqid());

    $command = fakeCommand();
    $command->callProtected('copyProjectFiles', $source, $destination, $stagingDir);

    expect(file_exists($destination.'/app/Foo.php'))->toBeTrue()
        ->and(is_dir($destination.'/storage/app/package-build-xyz'))->toBeFalse();
});

test('generateManifest declares the signature-covered post_update hook', function () {
    $stagingDir = storage_path('app/test-manifest-hook-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/.updater/post_update.php', '<?php return ["success" => true, "output" => ""];');
    $innerZip->close();

    $command = fakeCommand();
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0');

    $manifest = json_decode(file_get_contents($manifestPath), true);

    expect($manifest['post_update'])->toBe('.updater/post_update.php');
});

test('embedTooling stages updater.php and the post-update hook into the app tree', function () {
    $toolkitOutputDir = storage_path('app/test-embed-toolkit-'.uniqid());
    $stagedAppDir = storage_path('app/test-embed-app-'.uniqid());

    File::ensureDirectoryExists($toolkitOutputDir);
    File::ensureDirectoryExists($stagedAppDir);
    file_put_contents($toolkitOutputDir.'/updater.php', '<?php // fake updater');
    file_put_contents($toolkitOutputDir.'/post_update.php', '<?php return ["success" => true, "output" => ""];');

    $command = fakeCommand();
    $command->callProtected('embedTooling', $toolkitOutputDir, $stagedAppDir);

    expect(file_get_contents($stagedAppDir.'/public/updater.php'))->toBe('<?php // fake updater')
        ->and(file_exists($stagedAppDir.'/.updater/post_update.php'))->toBeTrue();
});

test('embedTooling fails loudly when the toolkit output is missing the updater', function () {
    $toolkitOutputDir = storage_path('app/test-embed-missing-'.uniqid());
    $stagedAppDir = storage_path('app/test-embed-missing-app-'.uniqid());

    File::ensureDirectoryExists($toolkitOutputDir);
    File::ensureDirectoryExists($stagedAppDir);

    $command = fakeCommand();

    expect(fn () => $command->callProtected('embedTooling', $toolkitOutputDir, $stagedAppDir))
        ->toThrow(RuntimeException::class, 'updater.php');
});

test('shouldExclude drops a stale updater.php from the app checkout', function () {
    $command = fakeCommand();

    expect($command->callProtected('shouldExclude', 'public/updater.php'))->toBeTrue()
        ->and($command->callProtected('shouldExclude', 'public/index.php'))->toBeFalse();
});
