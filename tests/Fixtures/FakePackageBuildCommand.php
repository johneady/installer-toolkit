<?php

namespace InstallerToolkit\Tests\Fixtures;

use InstallerToolkit\PackageBuildCommand;
use Symfony\Component\Console\Input\ArrayInput;

class FakePackageBuildCommand extends PackageBuildCommand
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

    public function callProtected(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this, $method))->invoke($this, ...$args);
    }
}
