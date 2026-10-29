<?php

use InstallerToolkit\Tests\TestCase;

uses(TestCase::class)->in('Installer');
uses(InstallerToolkit\Tests\Update\TestCase::class)->in('Update');

/*
|--------------------------------------------------------------------------
| TIA Engine (Test Impact Analysis)
|--------------------------------------------------------------------------
|
| The Tia Engine records which tests touch which src/ files, then re-runs
| only the tests affected by your latest changes, replaying cached results
| for the rest. `locally()` activates it on every local `pest` run without the
| `--tia` flag. It requires the PCOV coverage driver, which is installed in
| this project.
|
| Note that "locally" is not automatic: Pest's environment defaults to LOCAL
| and only becomes CI when `--ci` is passed explicitly. The `ci:check` Composer
| script passes that flag so the pipeline always runs the full suite rather
| than trusting a cached dependency graph.
|
| `composer test` passes `--no-tia` and always runs everything. The coverage
| graph can't see bin/build or the generated install.php/updater.php (they run
| as subprocesses), so a templates/ change would otherwise replay every cached
| result. The full suite takes seconds, so TIA buys nothing there.
|
| Changes that cannot alter behaviour (comments, docblocks, whitespace) are
| hashed out and correctly replay from cache rather than triggering a re-run.
|
| `defaultBranch('main')` names the baseline branch explicitly. This clone
| carries no origin/HEAD, so without it Tia can't tell which branch the cache
| should fall back to and aborts rather than guessing.
|
*/

pest()->tia()->defaultBranch('main')->locally();

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
