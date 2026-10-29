<?php

namespace InstallerToolkit\Tests;

use Illuminate\Contracts\Console\Kernel;
use InstallerToolkit\Tests\Fixtures\FakePackageBuildCommand;
use InstallerToolkit\Tests\Fixtures\FakePackageSandboxCommand;
use InstallerToolkit\Tests\Fixtures\FakePackageTestCommand;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Point the per-user signing key lookup at an empty directory, so a
     * developer's real ~/.config/installer-toolkit/signing.key never signs a
     * test build or turns an expected "no key" failure into a pass.
     */
    protected function setUp(): void
    {
        putenv('XDG_CONFIG_HOME='.sys_get_temp_dir().'/installer-toolkit-test-xdg-'.(getenv('TEST_TOKEN') ?: 'serial'));

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $kernel = $app->make(Kernel::class);
        $kernel->registerCommand(new FakePackageBuildCommand);
        $kernel->registerCommand(new FakePackageTestCommand);
        $kernel->registerCommand(new FakePackageSandboxCommand);
    }

    /**
     * Give every parallel worker its own base_path(). Testbench's default
     * skeleton app (vendor/orchestra/testbench-core/laravel) is a single
     * shared directory — every test's storage_path('app') and
     * base_path('package/package-config.php') collide across workers under
     * --parallel (ParaTest/Pest set TEST_TOKEN per worker process). Composer
     * autoloading is unaffected: it's resolved from vendor/autoload.php,
     * which lives outside the skeleton and is never copied.
     *
     * Outside --parallel (TEST_TOKEN unset), this returns the real skeleton
     * path unchanged.
     */
    protected function getApplicationBasePath(): string
    {
        $token = (string) (getenv('TEST_TOKEN') ?: '');

        if ($token === '') {
            return parent::getApplicationBasePath();
        }

        $workerPath = sys_get_temp_dir().'/installer-toolkit-testbench-worker-'.$token;

        if (! is_dir($workerPath)) {
            // The Illuminate app (and its File facade) isn't bootstrapped yet
            // at this point in Testbench's application-creation sequence, so
            // this copy has to use raw filesystem calls, not File::copyDirectory.
            self::copyDirectoryRaw(parent::getApplicationBasePath(), $workerPath);
        }

        return $workerPath;
    }

    private static function copyDirectoryRaw(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathname();

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
