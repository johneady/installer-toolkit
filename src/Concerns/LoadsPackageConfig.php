<?php

namespace InstallerToolkit\Concerns;

trait LoadsPackageConfig
{
    /**
     * Load and validate package/package-config.php, populating $this->config
     * and $this->slug. Returns null on success, or an error message to report
     * and fail the command with.
     */
    protected function loadPackageConfig(): ?string
    {
        $configPath = base_path('package/package-config.php');
        if (! file_exists($configPath)) {
            return 'No package-config.php found in package directory.';
        }

        $this->config = require $configPath;

        foreach (['name', 'slug', 'min_php_version'] as $requiredKey) {
            if (empty($this->config[$requiredKey])) {
                return "package-config.php is missing required key: '{$requiredKey}'.";
            }
        }

        $slug = $this->config['slug'];
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug)) {
            return "package-config.php's 'slug' must contain only lowercase letters, numbers, and hyphens, got: '{$slug}'.";
        }

        $this->slug = $slug;

        return null;
    }
}
