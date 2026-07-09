<?php

namespace InstallerToolkit\Tests\Fixtures;

use InstallerToolkit\PackageSandboxCommand;

class FakePackageSandboxCommand extends PackageSandboxCommand
{
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
