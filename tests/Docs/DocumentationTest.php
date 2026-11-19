<?php

use Illuminate\Support\Facades\File;
use InstallerToolkit\Docs\Documentation;

/**
 * A docs tree like an app's: docs/README.md, one guide and the changelog.
 *
 * @param  array<string, string>  $files  relative path => Markdown
 */
function docsTree(array $files): string
{
    $root = storage_path('app/docs-'.uniqid());

    foreach ($files as $path => $markdown) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        file_put_contents($root.'/'.$path, $markdown);
    }

    return $root;
}

function guides(string $root): Documentation
{
    return new Documentation([
        'overview' => 'docs/README.md',
        'stripe' => 'docs/stripe.md',
        'changelog' => 'CHANGELOG.md',
    ], $root);
}

function pageUrl(string $slug): string
{
    return "/admin/documentation?guide={$slug}&from=test";
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
});

test('only guides whose file exists are listed, in the given order', function () {
    $root = docsTree(['docs/README.md' => '# Overview', 'CHANGELOG.md' => '# Changelog']);

    expect(guides($root)->available())->toBe([
        'overview' => 'docs/README.md',
        'changelog' => 'CHANGELOG.md',
    ]);
});

test('an unknown or missing guide resolves to the first available one', function (?string $requested) {
    $root = docsTree(['docs/README.md' => '# Overview', 'CHANGELOG.md' => '# Changelog']);

    expect(guides($root)->resolve($requested))->toBe('overview');
})->with([
    'none requested' => null,
    'unknown slug' => 'nope',
    'a path' => '../../.env',
    'a listed guide whose file is missing' => 'stripe',
]);

test('a known guide resolves to itself', function () {
    $root = docsTree(['docs/README.md' => '# Overview', 'CHANGELOG.md' => '# Changelog']);

    expect(guides($root)->resolve('changelog'))->toBe('changelog');
});

test('nothing resolves when no guide is installed', function () {
    expect(guides(docsTree([]))->resolve('overview'))->toBeNull();
});

test('headings get GitHub-style ids so fragment links land', function () {
    $root = docsTree(['docs/README.md' => "# Overview\n\n## The cron job\n\nSee [below](#the-cron-job)."]);

    expect(guides($root)->render('overview', pageUrl(...)))
        ->toContain('<h1 id="overview">')
        ->toContain('<h2 id="the-cron-job">')
        ->toContain('href="#the-cron-job"');
});

test('links to another guide point at the page, keeping the fragment', function () {
    $root = docsTree([
        'docs/README.md' => "[Stripe](stripe.md), [keys](stripe.md#keys), [what's new](../CHANGELOG.md).",
        'docs/stripe.md' => '# Stripe',
        'CHANGELOG.md' => '# Changelog',
    ]);

    expect(guides($root)->render('overview', pageUrl(...)))
        ->toContain('href="/admin/documentation?guide=stripe&amp;from=test"')
        ->toContain('href="/admin/documentation?guide=stripe&amp;from=test#keys"')
        ->toContain('href="/admin/documentation?guide=changelog&amp;from=test"');
});

test('links to Markdown files that are not guides, and absolute links, are left alone', function () {
    $root = docsTree(['docs/README.md' => '[Old](docker.md) and [GitHub](https://example.com/notes.md).']);

    expect(guides($root)->render('overview', pageUrl(...)))
        ->toContain('href="docker.md"')
        ->toContain('href="https://example.com/notes.md"');
});

test('raw HTML and unsafe links are not passed through', function () {
    $root = docsTree(['CHANGELOG.md' => "# Changelog\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))"]);

    expect(guides($root)->render('changelog', pageUrl(...)))
        ->not->toContain('<script>')
        ->not->toContain('javascript:');
});

test('rendering a guide that is not available fails', function () {
    $root = docsTree(['docs/README.md' => '# Overview']);

    guides($root)->render('stripe', pageUrl(...));
})->throws(InvalidArgumentException::class);

test('links are matched by path, not file name, from the guide\'s own folder', function () {
    $root = docsTree([
        'docs/README.md' => '[Payments](payments/README.md) and [the repo readme](../README.md).',
        'docs/payments/README.md' => '[Back](../README.md)',
    ]);

    $guides = new Documentation([
        'overview' => 'docs/README.md',
        'payments' => 'docs/payments/README.md',
    ], $root);

    expect($guides->render('overview', pageUrl(...)))
        ->toContain('href="/admin/documentation?guide=payments&amp;from=test"')
        ->toContain('href="../README.md"')
        ->and($guides->render('payments', pageUrl(...)))
        ->toContain('href="/admin/documentation?guide=overview&amp;from=test"');
});

test('protocol-relative and root-relative links are left alone', function () {
    $root = docsTree([
        'docs/README.md' => '[a](//example.com/docs/stripe.md) [b](/docs/stripe.md)',
        'docs/stripe.md' => '# Stripe',
    ]);

    expect(guides($root)->render('overview', pageUrl(...)))
        ->toContain('href="//example.com/docs/stripe.md"')
        ->toContain('href="/docs/stripe.md"');
});
