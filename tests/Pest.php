<?php

use InstallerToolkit\Tests\TestCase;

uses(TestCase::class)->in('Installer');
uses(InstallerToolkit\Tests\Update\TestCase::class)->in('Update');

/**
 * Absolute path to the toolkit repo root (the directory holding composer.json,
 * bin/, templates/, config/). Anchored to this file's location so individual
 * tests never encode their own directory depth.
 */
function toolkitRoot(): string
{
    return dirname(__DIR__);
}

/**
 * Write a composer.json into the test app's base path declaring the given PHP
 * constraint. The toolkit derives min_php_version from this file, so any test
 * that runs loadPackageConfig() (via package:build, package:test, or
 * package:sandbox) needs one -- Testbench's skeleton composer.json declares no
 * 'require.php' of its own.
 */
function writeAppComposerJson(string $constraint = '^8.3'): void
{
    file_put_contents(
        base_path('composer.json'),
        json_encode(['require' => ['php' => $constraint]], JSON_PRETTY_PRINT)
    );
}
