<?php

declare(strict_types=1);

namespace InstallerToolkit\Tests\Update;

use FilesystemIterator;
use InstallerToolkit\Tests\Update\Support\BuildsUpdatePackages;
use InstallerToolkit\Update\UpdateServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class TestCase extends BaseTestCase
{
    use BuildsUpdatePackages;

    /**
     * Per-worker copy of Testbench's skeleton app, so tests that write to
     * base_path()/storage_path() (backups, restores, rollbacks) never race
     * against the same files in another parallel process. Testbench's
     * applicationBasePath() otherwise always resolves to the single shared
     * vendor/orchestra/testbench-core/laravel directory.
     */
    private static ?string $isolatedBasePath = null;

    public static function applicationBasePath(): string
    {
        if (self::$isolatedBasePath !== null) {
            return self::$isolatedBasePath;
        }

        $source = parent::applicationBasePath();
        $token = getenv('UNIQUE_TEST_TOKEN') ?: getenv('TEST_TOKEN') ?: (string) getmypid();
        $target = sys_get_temp_dir().'/update-toolkit-testbench-'.$token;

        if (! is_dir($target)) {
            static::copyDirectory($source, $target);
        }

        return self::$isolatedBasePath = $target;
    }

    /**
     * Facades aren't bootstrapped yet when this runs (applicationBasePath()
     * is called while building the app), so this can't use the File facade.
     */
    private static function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0755, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $destination = $target.DIRECTORY_SEPARATOR.$items->getSubPathname();

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
            } else {
                copy((string) $item->getPathname(), $destination);
            }
        }
    }

    protected function setUp(): void
    {
        // A prior test's UpdateService::optimize() call may have run the real
        // `config:cache` artisan command, writing Testbench's skeleton cache
        // file to disk. Once present, Laravel's mergeConfigFrom() skips
        // merging package config defaults entirely (it assumes a cached config
        // is already fully resolved), silently dropping this package's config
        // for every test that runs afterward in the same process. Clear it
        // before each test so config state never leaks across tests.
        $cachedConfig = static::applicationBasePath().'/bootstrap/cache/config.php';
        if (file_exists($cachedConfig)) {
            @unlink($cachedConfig);
        }

        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /**
     * Restrict backups to root-level files only so tests stay fast (the default
     * behaviour backs up the whole project).
     */
    protected function minimalBackupScope(): void
    {
        config(['updates.backup.exclude' => [
            'app', 'config', 'public', 'routes', 'bootstrap', 'resources',
            'database', 'lang', 'storage', 'tests', 'package', 'docs', 'bin',
            'vendor', 'node_modules', '.git', '.idea',
        ]]);
    }

    protected function getPackageProviders($app): array
    {
        return [UpdateServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.version', '1.0.0');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);

        $app['config']->set('updates.slug', 'testapp');
        // Mirrors the shipped config/updates.php default. Deliberately does
        // NOT include 'database/': new migration files must be extractable
        // or the update's migrate step silently applies nothing.
        $app['config']->set('updates.protected_paths', [
            '.env',
            'storage/app/license.key',
            'storage/app/public/',
            'storage/logs/',
            'storage/framework/sessions/',
        ]);

        // Signing is opt-in per test: most tests build unsigned packages and
        // are unrelated to signing, so they'd otherwise break the moment a
        // real key ships in config/updates.php's default. UpdateSigningTest
        // sets updates.signing.trusted_keys explicitly wherever it needs to
        // exercise enforcement.
        $app['config']->set('updates.signing.trusted_keys', []);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
