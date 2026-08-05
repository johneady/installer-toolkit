<?php

/**
 * Resolve the minimum PHP version an app's package artifacts should enforce.
 *
 * The value is baked as a literal into the generated install.php/updater.php
 * and into the update manifest's `minimum_php`, all of which run on customer
 * servers with no Composer and no vendor directory. It therefore has to be a
 * concrete string at build time rather than something resolved at runtime.
 *
 * It is derived from the app's own composer.json `require.php` constraint so
 * there is a single source of truth: an app that bumps its PHP requirement
 * cannot ship installers still advertising the old floor.
 *
 * This file is deliberately dependency-free and returns a closure rather than
 * declaring a namespaced function: bin/build is a standalone script with no
 * autoloader, and including the same file twice must not fatal on redeclare.
 */

return function (string $projectDir): array {
    $composerPath = rtrim($projectDir, '/').'/composer.json';

    if (! file_exists($composerPath)) {
        return ['version' => null, 'error' => "No composer.json found at {$composerPath}."];
    }

    $composer = json_decode((string) file_get_contents($composerPath), true);

    if (! is_array($composer)) {
        return ['version' => null, 'error' => "composer.json at {$composerPath} is not valid JSON."];
    }

    $constraint = $composer['require']['php'] ?? null;

    if (! is_string($constraint) || $constraint === '') {
        return ['version' => null, 'error' => "composer.json does not declare a 'require.php' constraint."];
    }

    /**
     * Take the first concrete version in the constraint and treat it as the
     * floor. This covers the forms actually used to express a minimum --
     * '^8.5', '>=8.5', '8.5.*', '^8.5.3', '>=8.4 <8.6' -- by reading the
     * leftmost bound, which is the floor in every one of them.
     *
     * A missing patch segment becomes .0, so '^8.5' yields '8.5.0' while an
     * explicitly pinned '^8.5.3' keeps its patch level and is not silently
     * widened to accept a build the app has already ruled out.
     */
    if (! preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $matches)) {
        return ['version' => null, 'error' => "Could not read a minimum PHP version from the constraint '{$constraint}'."];
    }

    $version = sprintf('%d.%d.%d', $matches[1], $matches[2], $matches[3] ?? 0);

    return ['version' => $version, 'error' => null];
};
