<?php

/**
 * Drives the real install-optimize.php that install.php generates: the
 * standalone endpoint the wizard's last task posts each optimize command to.
 * The app behind it is a double (a bootstrap/app.php whose kernel records
 * what it runs), because the endpoint's contract with the app is only
 * Kernel::all() and Kernel::call().
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();
    }

    foreach ($this->cleanup ?? [] as $directory) {
        File::deleteDirectory($directory);
    }
});

test('the optimizer skips commands the app does not register and runs the rest', function () {
    $slug = 'optimizer-app';
    $projectDir = storage_path('app/optimizer-build-'.uniqid());
    $outputDir = storage_path('app/optimizer-out-'.uniqid());
    $sandbox = storage_path('app/optimizer-sandbox-'.uniqid());
    $this->cleanup = [$projectDir, $outputDir, $sandbox];

    File::ensureDirectoryExists($projectDir.'/package');
    file_put_contents($projectDir.'/package/package-config.php', '<?php return '.var_export([
        'name' => 'Optimizer App',
        'slug' => $slug,
        'essential_seeders' => [],
        'sample_seeders' => [],
    ], true).';');
    file_put_contents($projectDir.'/composer.json', json_encode(['require' => ['php' => '^8.2']]));
    (new Process(['php', toolkitRoot().'/bin/build', $projectDir, $outputDir]))->mustRun();

    // install.php with its run() call removed, so requiring it only defines
    // the Installer class for the driver below to generate the endpoint.
    $installer = file_get_contents($outputDir.'/install.php');
    $definitionsOnly = str_replace('(new Installer)->run();', '', $installer, $replaced);
    expect($replaced)->toBe(1);

    $appDir = $sandbox.'/'.$slug;
    File::ensureDirectoryExists($appDir.'/public');
    File::ensureDirectoryExists($appDir.'/vendor');
    File::ensureDirectoryExists($appDir.'/bootstrap');
    file_put_contents($sandbox.'/install.php', $definitionsOnly);
    file_put_contents($appDir.'/vendor/autoload.php', '<?php require '.var_export(toolkitRoot().'/vendor/autoload.php', true).';');
    file_put_contents($appDir.'/bootstrap/app.php', <<<'PHP'
        <?php

        // An app with no Blade Icons or Filament: icons:cache is not registered.
        return new class
        {
            public function make(string $abstract): object
            {
                return new class
                {
                    public function all(): array
                    {
                        return ['config:clear' => true, 'view:cache' => true];
                    }

                    public function call(string $command, array $parameters = [], $output = null): int
                    {
                        file_put_contents(__DIR__.'/../ran.log', $command."\n", FILE_APPEND);

                        return 0;
                    }
                };
            }
        };
        PHP);

    $driver = $sandbox.'/generate-optimizer.php';
    file_put_contents($driver, <<<'PHP'
        <?php
        $_SESSION = ['installer' => ['optimize_token' => 'test-token']];
        require __DIR__.'/install.php';
        $installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
        (fn () => $this->ensureOptimizerEndpoint())->call($installer);
        PHP);
    (new Process(['php', $driver]))->mustRun();

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUri = 'http://127.0.0.1:'.substr($name, strrpos($name, ':') + 1);

    $this->server = new Process(['php', '-S', substr($baseUri, 7), '-t', $appDir.'/public']);
    $this->server->start();
    $this->server->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'started'));

    $commands = 'config:clear,icons:cache,view:cache';
    $responses = collect([0, 1, 2])->map(fn (int $index) => Http::asForm()
        ->post($baseUri.'/install-optimize.php', ['token' => 'test-token', 'commands' => $commands, 'index' => $index])
        ->json());

    expect($responses->pluck('success')->all())->toBe([true, true, true])
        ->and($responses[1]['message'])->toBe('Skipped icons:cache (2/3).')
        ->and($responses[1]['done'])->toBeFalse()
        ->and($responses[2]['done'])->toBeTrue()
        ->and(file($appDir.'/ran.log', FILE_IGNORE_NEW_LINES))->toBe(['config:clear', 'view:cache']);
});
