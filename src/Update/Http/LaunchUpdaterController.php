<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InstallerToolkit\Update\HandoffToken;
use InstallerToolkit\Update\UpdateAuthorizer;

/**
 * The single endpoint behind "Launch Updater": authorize the admin, mint a
 * one-time handoff token, and redirect to the standalone updater.
 *
 * Everything heavy — upload, validation, progress, extraction, rollback —
 * happens inside public/updater.php. This controller only hands off control,
 * which is the entire reason the integration surface shrank: a route and a
 * redirect, nothing framework-version-coupled.
 */
class LaunchUpdaterController extends Controller
{
    public function __construct(
        private UpdateAuthorizer $authorizer,
        private HandoffToken $tokens,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $this->authorizer->allows($request)) {
            abort(403, 'You do not have permission to launch the updater.');
        }

        return redirect()->away($this->tokens->launchUrl());
    }
}
