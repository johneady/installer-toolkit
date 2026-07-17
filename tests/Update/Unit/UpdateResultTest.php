<?php

declare(strict_types=1);

use InstallerToolkit\Update\UpdateResult;

it('formats a valid finishedAt for display', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => '2026-07-10T10:00:00-04:00']);

    expect($result->finishedAtDisplay())->toBe('2026-07-10 10:00:00');
});

it('returns null when finishedAt is absent', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied']);

    expect($result->finishedAtDisplay())->toBeNull();
});

it('returns null instead of throwing when finishedAt is unparseable', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => 'not-a-date']);

    expect($result->finishedAtDisplay())->toBeNull();
});

it('returns null rather than "now" when finishedAt is an empty string', function (): void {
    $result = UpdateResult::fromArray(['status' => 'applied', 'finished_at' => '']);

    expect($result->finishedAtDisplay())->toBeNull();
});
