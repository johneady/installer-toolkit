<?php

namespace InstallerToolkit\Tests;

use InstallerToolkit\Tests\Fixtures\FakePackageBuildCommand;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand(new FakePackageBuildCommand);
    }
}
