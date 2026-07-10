<?php

namespace InstallerToolkit\Tests\Fixtures;

use InstallerToolkit\PackageTestCommand;
use Symfony\Component\Console\Input\ArrayInput;

class FakePackageTestCommand extends PackageTestCommand
{
    /** @var array<array{0: string, 1: array}> */
    public array $recordedCalls = [];

    /**
     * Record sub-command invocations (package:build) instead of executing
     * them, so runBuild() can be exercised without a real build.
     */
    public function call($command, array $arguments = []): int
    {
        $this->recordedCalls[] = [$command, $arguments];

        return self::SUCCESS;
    }

    /**
     * Bind an input against the command's own definition so option() works
     * (with signature defaults) outside a real run() cycle.
     */
    public function withInput(array $parameters = []): static
    {
        (new \ReflectionProperty($this, 'input'))->setValue(
            $this,
            new ArrayInput($parameters, $this->getDefinition()),
        );

        return $this;
    }

    public function withBuildOutputDir(string $dir): static
    {
        (new \ReflectionProperty($this, 'buildOutputDir'))->setValue($this, $dir);

        return $this;
    }

    /**
     * Set $slug/$config directly, mirroring what handle() would populate
     * from package-config.php, so protected methods can be exercised
     * individually via reflection without running the full command.
     */
    public function withConfig(array $config): static
    {
        $config = array_merge([
            'name' => 'Fake App',
            'slug' => 'fake-app',
            'min_php_version' => '8.3.0',
        ], $config);

        (new \ReflectionProperty($this, 'slug'))->setValue($this, $config['slug']);
        (new \ReflectionProperty($this, 'config'))->setValue($this, $config);

        return $this;
    }

    public function callProtected(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this, $method))->invoke($this, ...$args);
    }
}
