<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use InstallerToolkit\Update\HandoffToken;

function handoffFile(): string
{
    return app(HandoffToken::class)->handoffFile();
}

beforeEach(function (): void {
    config(['app.url' => 'https://example.test']);
    @unlink(handoffFile());
});

it('mints a 64-char token and writes a handoff.json with a sha256 hash and expiry', function (): void {
    $token = app(HandoffToken::class)->mint();

    expect(strlen($token))->toBe(64)
        ->and(file_exists(handoffFile()))->toBeTrue();

    $handoff = json_decode((string) file_get_contents(handoffFile()), true);

    expect($handoff['token_hash'])->toBe(hash('sha256', $token))
        ->and($handoff['expires_at'])->toBeGreaterThan(time());
});

it('honors a configured handoff ttl', function (): void {
    config(['updates.updater.handoff_ttl' => 60]);

    app(HandoffToken::class)->mint();

    $handoff = json_decode((string) file_get_contents(handoffFile()), true);

    expect($handoff['expires_at'])->toBeLessThanOrEqual(time() + 60)
        ->and($handoff['expires_at'])->toBeGreaterThan(time());
});

it('uses a zero expiry when the ttl is disabled', function (): void {
    config(['updates.updater.handoff_ttl' => 0]);

    app(HandoffToken::class)->mint();

    $handoff = json_decode((string) file_get_contents(handoffFile()), true);

    expect($handoff['expires_at'])->toBe(0);
});

it('overwrites any previous handoff file on each mint', function (): void {
    $first = app(HandoffToken::class)->mint();
    $second = app(HandoffToken::class)->mint();

    $handoff = json_decode((string) file_get_contents(handoffFile()), true);

    expect($first)->not->toBe($second)
        ->and($handoff['token_hash'])->toBe(hash('sha256', $second))
        ->and($handoff['token_hash'])->not->toBe(hash('sha256', $first));
});

it('builds an absolute launch url carrying the freshly-minted token, derived from APP_URL', function (): void {
    $url = app(HandoffToken::class)->launchUrl();

    expect($url)->toStartWith('https://example.test/updater.php?token=');

    $token = substr($url, strlen('https://example.test/updater.php?token='));
    $handoff = json_decode((string) file_get_contents(handoffFile()), true);

    expect(strlen($token))->toBe(64)
        ->and(hash('sha256', $token))->toBe($handoff['token_hash']);
});

it('derives the launch host from config, never from the request Host header', function (): void {
    // A forged Host header must not move the redirect off the trusted APP_URL,
    // since the URL carries the live handoff token.
    app()->instance('request', Request::create('https://attacker.test', 'GET'));

    expect(app(HandoffToken::class)->launchUrl())
        ->toStartWith('https://example.test/updater.php?token=');
});

it('respects a configured updater path in the launch url', function (): void {
    config(['updates.updater.path' => 'tools/updater.php']);

    expect(app(HandoffToken::class)->launchUrl())
        ->toStartWith('https://example.test/tools/updater.php?token=');
});
