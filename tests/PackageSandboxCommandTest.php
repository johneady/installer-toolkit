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

test('handle fails when slug contains characters outside docker/path-safe charset', function () {
    File::ensureDirectoryExists(base_path('package'));
    $config = ['name' => 'Fake App', 'slug' => 'my app!', 'min_php_version' => '8.3.0'];
    file_put_contents(base_path('package/package-config.php'), '<?php return '.var_export($config, true).';');

    $this->artisan('package:sandbox')
        ->assertFailed()
        ->expectsOutputToContain("'slug' must contain only lowercase letters, numbers, and hyphens");
});

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

test('generated router serves a nested public asset requested by its root-relative URL', function () {
    $command = fakeSandboxCommand(['slug' => 'fake-app']);

    $tempDir = storage_path('app/sandbox-router-asset-test');
    File::ensureDirectoryExists($tempDir.'/fake-app/public/build/assets');
    file_put_contents($tempDir.'/fake-app/public/build/assets/app.js', 'console.log("fake-app");');

    $routerPath = $command->callProtected('generateRouterScript', $tempDir);

    $port = $command->callProtected('findFreePort');
    $process = new \Symfony\Component\Process\Process(['php', '-S', "127.0.0.1:{$port}", '-t', $tempDir, $routerPath]);
    $process->start();
    (new ReflectionProperty($command, 'serverProcess'))->setValue($command, $process);

    try {
        $command->callProtected('waitForServer', $port);

        $response = \Illuminate\Support\Facades\Http::get("http://127.0.0.1:{$port}/build/assets/app.js");

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('console.log("fake-app");')
            ->and($response->header('Content-Type'))->toContain('javascript');
    } finally {
        $process->stop(3);
    }
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

test('waitForServer returns once the PHP built-in server accepts connections', function () {
    $command = fakeSandboxCommand();

    $port = $command->callProtected('findFreePort');
    $process = new \Symfony\Component\Process\Process(['php', '-S', "127.0.0.1:{$port}"]);
    $process->start();
    (new ReflectionProperty($command, 'serverProcess'))->setValue($command, $process);

    try {
        $command->callProtected('waitForServer', $port);
    } finally {
        $process->stop(3);
    }
})->throwsNoExceptions();

test('waitForServer throws immediately when the server process is not running', function () {
    $command = fakeSandboxCommand();

    $port = $command->callProtected('findFreePort');
    $process = new \Symfony\Component\Process\Process(['php', '-r', 'exit(1);']);
    $process->run();
    (new ReflectionProperty($command, 'serverProcess'))->setValue($command, $process);

    expect(fn () => $command->callProtected('waitForServer', $port))
        ->toThrow(RuntimeException::class, 'PHP built-in server failed to start');
});

test('teardown with --keep leaves the temp dir and stops only the server process', function () {
    $command = fakeSandboxCommand();

    $tempDir = storage_path('app/sandbox-keep-test');
    File::ensureDirectoryExists($tempDir);

    (new ReflectionProperty($command, 'tempDir'))->setValue($command, $tempDir);
    (new ReflectionProperty($command, 'mysqlContainerName'))->setValue($command, 'package-sandbox-fake-app-keep-test');

    $command->withOptions(['keep' => true]);

    $command->callProtected('teardown');

    expect(is_dir($tempDir))->toBeTrue();

    File::deleteDirectory($tempDir);
});
