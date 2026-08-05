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

        foreach (['name', 'slug'] as $requiredKey) {
            if (empty($this->config[$requiredKey])) {
                return "package-config.php is missing required key: '{$requiredKey}'.";
            }
        }

        /**
         * Derived from composer.json rather than read from package-config.php,
         * so the floor baked into generated installers can never drift from the
         * requirement the app actually enforces. Any 'min_php_version' left in
         * a package-config.php is ignored.
         */
        $resolveMinPhpVersion = require __DIR__.'/../min_php_version.php';
        $resolved = $resolveMinPhpVersion(base_path());

        if ($resolved['error'] !== null) {
            return "Could not determine the minimum PHP version: {$resolved['error']}";
        }

        $this->config['min_php_version'] = $resolved['version'];

        $slug = $this->config['slug'];
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug)) {
            return "package-config.php's 'slug' must contain only lowercase letters, numbers, and hyphens, got: '{$slug}'.";
        }

        $this->slug = $slug;

        return null;
    }

    /**
     * Resolve an --output option value to an absolute directory: absolute
     * paths pass through untouched (used by package:test to redirect the
     * build into an isolated temp dir), relative paths resolve against the
     * app's base path.
     */
    protected function resolveOutputDir(string $output): string
    {
        return str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : base_path($output);
    }
}
