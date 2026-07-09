<?php

use Illuminate\Support\Facades\File;
use InstallerToolkit\Tests\Fixtures\FakePackageSandboxCommand;

function fakeSandboxCommand(array $config = []): FakePackageSandboxCommand
{
    $command = new FakePackageSandboxCommand;
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\NullOutput,
    ));

    return $command->withConfig($config);
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
    File::delete(base_path('package/package-config.php'));
});

test('handle fails when package-config.php is missing', function () {
    File::delete(base_path('package/package-config.php'));

    $this->artisan('package:sandbox')
        ->assertFailed()
        ->expectsOutputToContain('No package-config.php found');
});

test('handle fails when package-config.php is missing a required key', function (string $missingKey) {
    $config = [
        'name' => 'Fake App',
        'slug' => 'fake-app',
        'min_php_version' => '8.3.0',
    ];
    unset($config[$missingKey]);

    File::ensureDirectoryExists(base_path('package'));
    file_put_contents(base_path('package/package-config.php'), '<?php return '.var_export($config, true).';');

    $this->artisan('package:sandbox')
        ->assertFailed()
        ->expectsOutputToContain("missing required key: '{$missingKey}'");
})->with(['name', 'slug', 'min_php_version']);

test('handle fails when the built full zip is missing', function () {
    File::ensureDirectoryExists(base_path('package'));
    $config = ['name' => 'Fake App', 'slug' => 'fake-app', 'min_php_version' => '8.3.0'];
    file_put_contents(base_path('package/package-config.php'), '<?php return '.var_export($config, true).';');

    $this->artisan('package:sandbox')
        ->assertFailed()
        ->expectsOutputToContain('Expected built package not found');
});

test('handle fails when --mysql-port is not a non-negative integer', function () {
    File::ensureDirectoryExists(base_path('package'));
    $config = ['name' => 'Fake App', 'slug' => 'fake-app', 'min_php_version' => '8.3.0'];
    file_put_contents(base_path('package/package-config.php'), '<?php return '.var_export($config, true).';');

    File::ensureDirectoryExists(base_path('package/packages'));
    file_put_contents(base_path('package/packages/fake-app-v'.config('app.version').'-full.zip'), 'not a real zip');

    $this->artisan('package:sandbox', ['--mysql-port' => 'not-a-number'])
        ->assertFailed()
        ->expectsOutputToContain('--mysql-port must be a non-negative integer');

    File::deleteDirectory(base_path('package/packages'));
});

test('findFreePort returns a usable, immediately reusable port', function () {
    $command = fakeSandboxCommand();

    $port = $command->callProtected('findFreePort');

    expect($port)->toBeInt()->toBeGreaterThan(0)->toBeLessThan(65536);

    $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");
    expect($socket)->not->toBeFalse();
    fclose($socket);
});

test('generateRouterScript writes a router that protects install.php and the app public dir', function () {
    $command = fakeSandboxCommand(['slug' => 'fake-app']);

    $tempDir = storage_path('app/sandbox-router-test');
    File::ensureDirectoryExists($tempDir);

    $routerPath = $command->callProtected('generateRouterScript', $tempDir);

    expect($routerPath)->toBe($tempDir.'/.package-sandbox-router.php')
        ->and(file_exists($routerPath))->toBeTrue();

    $contents = file_get_contents($routerPath);

    expect($contents)->toContain("/install.php")
        ->and($contents)->toContain('/fake-app/public')
        ->and($contents)->toContain('_cleanup.php');
});

test('extractOuterPackage throws when install.php is missing from the zip', function () {
    $command = fakeSandboxCommand(['slug' => 'fake-app']);

    $zipPath = storage_path('app/empty-sandbox-test.zip');
    File::ensureDirectoryExists(dirname($zipPath));

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'no installer here');
    $zip->close();

    $tempDir = storage_path('app/sandbox-extract-test');

    expect(fn () => $command->callProtected('extractOuterPackage', $zipPath, $tempDir))
        ->toThrow(RuntimeException::class, 'install.php not found after extracting the built package.');

    File::delete($zipPath);
});
