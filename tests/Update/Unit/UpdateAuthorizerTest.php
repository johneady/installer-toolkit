<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use InstallerToolkit\Update\UpdateAuthorizer;

it('allows when the configured authorizer returns true', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => true]);

    expect(app(UpdateAuthorizer::class)->allows(Request::create('/x', 'POST')))->toBeTrue();
});

it('denies when the configured authorizer returns false', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => false]);

    expect(app(UpdateAuthorizer::class)->allows(Request::create('/x', 'POST')))->toBeFalse();
});

it('falls back to the authenticated admin check when no authorizer is set', function (): void {
    config(['updates.authorize_upload' => null]);

    $admin = new class
    {
        public bool $is_admin = true;
    };

    $nonAdmin = new class
    {
        public bool $is_admin = false;
    };

    $adminRequest = Request::create('/x', 'POST');
    $adminRequest->setUserResolver(fn () => $admin);

    $nonAdminRequest = Request::create('/x', 'POST');
    $nonAdminRequest->setUserResolver(fn () => $nonAdmin);

    $authorizer = app(UpdateAuthorizer::class);

    expect($authorizer->allows($adminRequest))->toBeTrue()
        ->and($authorizer->allows($nonAdminRequest))->toBeFalse();
});

it('denies when nobody is authenticated', function (): void {
    config(['updates.authorize_upload' => null]);

    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => null);

    expect(app(UpdateAuthorizer::class)->allows($request))->toBeFalse();
});
