<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Post-update hook — shipped inside every .update package's signed inner
 * zip at {slug}/.updater/post_update.php and executed by updater.php after
 * all files are extracted.
 *
 * This is the ONLY point in an update where the application framework
 * boots, and it deliberately boots the freshly-updated code: migrations and
 * cache rebuilds must run against the new tree, never a half-old one.
 *
 * Contract: including this file returns ['success' => bool, 'output' => string].
 * It never echoes and never throws — the updater surfaces the output verbatim.
 */

return (function (): array {
    $appRoot = dirname(__DIR__);
    $log = [];

    try {
        // Providers commonly gate Artisan command registration behind
        // runningInConsole() (Filament, Blade Icons, …) — make the boot look
        // like a console run so those commands exist.
        putenv('APP_RUNNING_IN_CONSOLE=true');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'true';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';

        $autoload = $appRoot.'/vendor/autoload.php';
        if (! is_file($autoload)) {
            return ['success' => false, 'output' => 'vendor/autoload.php is missing — the update did not extract correctly.'];
        }

        require $autoload;
        $app = require $appRoot.'/bootstrap/app.php';

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $run = function (string $command, array $params = []) use ($kernel, &$log): void {
            $output = new BufferedOutput;

            ob_start();
            try {
                $exitCode = $kernel->call($command, $params + ['--no-interaction' => true], $output);
            } finally {
                ob_end_clean();
            }

            $text = trim($output->fetch());
            $log[] = "$ {$command}".($text !== '' ? "\n{$text}" : '');

            if ($exitCode !== 0) {
                throw new RuntimeException("{$command} failed (exit code {$exitCode}): ".substr($text ?: 'Unknown error', 0, 500));
            }
        };

        $run('migrate', ['--force' => true]);

        try {
            $run('storage:link');
        } catch (Throwable $e) {
            $log[] = 'Warning: storage:link skipped: '.$e->getMessage();
        }

        $clearCommands = (array) config('updates.clear_cache_commands', [
            'config:clear', 'route:clear', 'view:clear', 'event:clear',
        ]);

        foreach ($clearCommands as $command) {
            $run($command);
        }

        // Optimize commands are best-effort, matching the Laravel package's
        // UpdateService::optimize(): a failed cache rebuild is a warning,
        // not a broken update.
        $optimizeCommands = (array) config('updates.optimize_commands', [
            'config:cache', 'route:cache', 'view:cache', 'event:cache',
        ]);

        foreach ($optimizeCommands as $command) {
            try {
                $run($command);
            } catch (Throwable $e) {
                $log[] = "Warning: {$command} failed: ".$e->getMessage();
            }
        }

        return ['success' => true, 'output' => implode("\n", $log)];
    } catch (Throwable $e) {
        $log[] = $e->getMessage();

        return ['success' => false, 'output' => implode("\n", $log)];
    }
})();
