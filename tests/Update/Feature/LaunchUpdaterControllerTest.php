<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use InstallerToolkit\Update\HandoffToken;

beforeEach(function (): void {
    // The package registers the launch route under ['web', 'auth'] middleware.
    // The controller's own UpdateAuthorizer is the real gate, so disable all
    // middleware here and exercise authorization via the config callable.
    $this->withoutMiddleware();

    config(['app.url' => 'http://example.test']);
    @unlink(app(HandoffToken::class)->handoffFile());
});

it('redirects to the standalone updater with a handoff token when authorized', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => true]);

    $response = $this->post('/system-update/launch');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    $handoffFile = app(HandoffToken::class)->handoffFile();

    expect($location)->toStartWith('http://example.test/updater.php?token=')
        ->and(file_exists($handoffFile))->toBeTrue();

    $token = substr($location, strlen('http://example.test/updater.php?token='));
    $handoff = json_decode((string) file_get_contents($handoffFile), true);

    expect(strlen($token))->toBe(64)
        ->and(hash('sha256', $token))->toBe($handoff['token_hash']);
});

it('is forbidden and mints no token when the authorizer denies', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => false]);

    $this->post('/system-update/launch')->assertForbidden();

    expect(file_exists(app(HandoffToken::class)->handoffFile()))->toBeFalse();
});

it('registers the launch route under a configurable path', function (): void {
    expect(route('updater.launch'))->toEndWith('/system-update/launch');
});
