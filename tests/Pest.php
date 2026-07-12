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
