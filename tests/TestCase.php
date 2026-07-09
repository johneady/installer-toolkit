<?php

namespace InstallerToolkit\Tests;

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

    protected function defineEnvironment($app): void
    {
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->registerCommand(new FakePackageBuildCommand);
        $kernel->registerCommand(new FakePackageTestCommand);
        $kernel->registerCommand(new FakePackageSandboxCommand);
    }
}
