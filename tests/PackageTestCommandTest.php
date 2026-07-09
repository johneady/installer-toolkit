<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InstallerToolkit\PackageTestCommand;
use InstallerToolkit\Tests\Fixtures\FakePackageTestCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function fakeTestCommand(array $config = []): FakePackageTestCommand
{
    $command = new FakePackageTestCommand;
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new NullOutput,
    ));

    return $command->withConfig($config);
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
    File::delete(base_path('package/package-config.php'));
});

test('handle fails when package-config.php is missing', function () {
    File::delete(base_path('package/package-config.php'));

    $this->artisan('package:test')
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

    $this->artisan('package:test')
        ->assertFailed()
        ->expectsOutputToContain("missing required key: '{$missingKey}'");
})->with(['name', 'slug', 'min_php_version']);

test('findFreePort returns a usable, immediately reusable port', function () {
    $command = fakeTestCommand();

    $port = $command->callProtected('findFreePort');

    expect($port)->toBeInt()->toBeGreaterThan(0)->toBeLessThan(65536);

    // The port must be free again immediately after — proves findFreePort()
    // released its probe socket rather than leaking it.
    $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");
    expect($socket)->not->toBeFalse();
    fclose($socket);
});

test('findFreePort returns distinct ports across calls', function () {
    $command = fakeTestCommand();

    $ports = [
        $command->callProtected('findFreePort'),
        $command->callProtected('findFreePort'),
        $command->callProtected('findFreePort'),
    ];

    expect(array_unique($ports))->toHaveCount(3);
});

test('TASK_SEQUENCE matches the task list install.php drives', function () {
    $reflection = new ReflectionClass(PackageTestCommand::class);

    expect($reflection->getConstant('TASK_SEQUENCE'))->toBe([
        'extract',
        'htaccess',
        'env',
        'migrate',
        'seed',
        'storage_link',
        'config_clear',
        'package_discover',
        'config_cache',
        'event_cache',
        'route_cache',
        'view_cache',
        'icons_cache',
        'filament_optimize',
    ]);
});

test('CLEAN_PROCESS_TASKS matches the optimize-endpoint command map install.php uses', function () {
    $reflection = new ReflectionClass(PackageTestCommand::class);

    expect($reflection->getConstant('CLEAN_PROCESS_TASKS'))->toBe([
        'config_clear' => 'config:clear',
        'package_discover' => 'package:discover',
        'config_cache' => 'config:cache',
        'event_cache' => 'event:cache',
        'route_cache' => 'route:cache',
        'view_cache' => 'view:cache',
        'icons_cache' => 'icons:cache',
        'filament_optimize' => 'filament:optimize',
    ]);
});

test('runInstallTask polls extract until extract_done is true', function () {
    Http::fake([
        '*/install.php*' => Http::sequence()
            ->push(['success' => true, 'extract_done' => false, 'message' => '50%'])
            ->push(['success' => true, 'extract_done' => false, 'message' => '90%'])
            ->push(['success' => true, 'extract_done' => true, 'message' => 'done']),
    ]);

    $command = fakeTestCommand();
    $client = Http::baseUrl('http://127.0.0.1:0');
    $command->callProtected('runInstallTask', $client, 'extract', 'deadbeef');

    Http::assertSentCount(3);
});

test('runInstallTask switches to seed_batch after the first seed response', function () {
    Http::fake([
        '*/install.php*' => Http::sequence()
            ->push(['success' => true, 'seed_done' => false, 'message' => 'seeder 1'])
            ->push(['success' => true, 'seed_done' => false, 'message' => 'seeder 2'])
            ->push(['success' => true, 'seed_done' => true, 'message' => 'done']),
    ]);

    $command = fakeTestCommand();
    $client = Http::baseUrl('http://127.0.0.1:0');
    $command->callProtected('runInstallTask', $client, 'seed', 'deadbeef');

    $requestedUrls = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->url())->all();

    expect($requestedUrls[0])->toContain('task=seed')
        ->and($requestedUrls[0])->not->toContain('task=seed_batch')
        ->and($requestedUrls[1])->toContain('task=seed_batch')
        ->and($requestedUrls[2])->toContain('task=seed_batch');
});

test('runInstallTask throws with the server message when a task fails', function () {
    Http::fake([
        '*/install.php*' => Http::response(['success' => false, 'message' => 'migrate:fresh failed (exit code 1): boom']),
    ]);

    $command = fakeTestCommand();
    $client = Http::baseUrl('http://127.0.0.1:0');

    expect(fn () => $command->callProtected('runInstallTask', $client, 'migrate', 'deadbeef'))
        ->toThrow(RuntimeException::class, 'migrate:fresh failed (exit code 1): boom');
});

test('runInstallTask follows up clean-process tasks through the optimize endpoint', function () {
    Http::fake([
        '*/install-optimize.php*' => Http::response(['success' => true, 'message' => 'Completed.']),
        '*/install.php*' => Http::response(['success' => true]),
    ]);

    $command = fakeTestCommand(['slug' => 'fake-app']);
    $client = Http::baseUrl('http://127.0.0.1:0');
    $command->callProtected('runInstallTask', $client, 'config_cache', 'deadbeef');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'install-optimize.php')
            && str_contains((string) $request->url(), 'command=config%3Acache')
            && str_contains((string) $request->url(), 'token=deadbeef');
    });
});

test('runInstallTask throws when the optimize endpoint itself fails', function () {
    Http::fake([
        '*/install-optimize.php*' => Http::response(['success' => false, 'message' => 'config:cache failed (exit code 1): bad env']),
        '*/install.php*' => Http::response(['success' => true]),
    ]);

    $command = fakeTestCommand(['slug' => 'fake-app']);
    $client = Http::baseUrl('http://127.0.0.1:0');

    expect(fn () => $command->callProtected('runInstallTask', $client, 'config_cache', 'deadbeef'))
        ->toThrow(RuntimeException::class, 'config:cache failed (exit code 1): bad env');
});
