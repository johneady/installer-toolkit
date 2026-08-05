<?php

use Illuminate\Support\Facades\File;

/**
 * The resolver is a dependency-free closure so bin/build (which runs with no
 * autoloader) can include it directly; these tests exercise it the same way.
 */
function resolveMinPhpVersion(string $projectDir): array
{
    $resolver = require toolkitRoot().'/src/min_php_version.php';

    return $resolver($projectDir);
}

function projectWithPhpConstraint(mixed $constraint): string
{
    $dir = storage_path('app/min-php-'.uniqid());
    File::ensureDirectoryExists($dir);

    $composer = $constraint === null ? ['require' => []] : ['require' => ['php' => $constraint]];
    file_put_contents($dir.'/composer.json', json_encode($composer));

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
});

test('derives the floor from the constraint forms used to express a minimum', function (string $constraint, string $expected) {
    $result = resolveMinPhpVersion(projectWithPhpConstraint($constraint));

    expect($result['error'])->toBeNull()
        ->and($result['version'])->toBe($expected);
})->with([
    'caret minor' => ['^8.3', '8.3.0'],
    'caret patch' => ['^8.5.3', '8.5.3'],
    'gte minor' => ['>=8.4', '8.4.0'],
    'gte patch' => ['>=8.4.1', '8.4.1'],
    'wildcard' => ['8.5.*', '8.5.0'],
    'tilde' => ['~8.2.0', '8.2.0'],
    // The leftmost bound is the floor in a bounded range.
    'bounded range' => ['>=8.4 <8.6', '8.4.0'],
    'or-list takes the lowest stated first' => ['^8.3|^8.4', '8.3.0'],
]);

test('always yields a SemVer string, the shape bin/build interpolates into generated PHP', function (string $constraint) {
    $result = resolveMinPhpVersion(projectWithPhpConstraint($constraint));

    expect($result['version'])->toMatch('/^\d+\.\d+\.\d+$/');
})->with(['^8.3', '^8.5.3', '>=8.4 <8.6', '8.5.*', '~8.2.0']);

test('reports an error when composer.json is missing', function () {
    $dir = storage_path('app/min-php-absent-'.uniqid());
    File::ensureDirectoryExists($dir);

    $result = resolveMinPhpVersion($dir);

    expect($result['version'])->toBeNull()
        ->and($result['error'])->toContain('No composer.json found');
});

test('reports an error when composer.json is not valid JSON', function () {
    $dir = storage_path('app/min-php-invalid-'.uniqid());
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/composer.json', '{not json');

    $result = resolveMinPhpVersion($dir);

    expect($result['version'])->toBeNull()
        ->and($result['error'])->toContain('not valid JSON');
});

test('reports an error when no php constraint is declared', function () {
    $result = resolveMinPhpVersion(projectWithPhpConstraint(null));

    expect($result['version'])->toBeNull()
        ->and($result['error'])->toContain("'require.php'");
});

test('reports an error when the constraint holds no readable version', function () {
    $result = resolveMinPhpVersion(projectWithPhpConstraint('*'));

    expect($result['version'])->toBeNull()
        ->and($result['error'])->toContain("constraint '*'");
});

test('tolerates a trailing slash on the project directory', function () {
    $dir = projectWithPhpConstraint('^8.3');

    expect(resolveMinPhpVersion($dir.'/')['version'])->toBe('8.3.0');
});
