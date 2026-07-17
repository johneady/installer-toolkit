<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Illuminate\Support\Facades\File;

/**
 * Mints the one-time handoff token the standalone updater consumes.
 *
 * When an admin clicks "Launch Updater", the application mints a token and
 * redirects to public/updater.php?token=… . The built updater's
 * GuardsUpdaterAccess::consumeToken() verifies the hash (constant-time),
 * deletes the file, and authorizes the session. The token itself is never
 * stored in cleartext — only its sha256 — and the file is single-use by
 * construction: the updater removes it the instant it authorizes a session.
 *
 * This runs from inside the live application (a normal booted Laravel
 * request), unlike updater.php itself, which is framework-free.
 *
 * The handoff file is a single slot, not a queue: mint() overwrites
 * handoff.json unconditionally, so if two admins click "Launch Updater"
 * within the same window, only the most recently minted token is valid —
 * the first admin's redirect lands on the token gate with "not recognized"
 * instead of authorizing. This is self-healing (relaunching mints a fresh
 * token and works normally) and deliberate: supporting concurrent handoffs
 * would need a keyed/multi-token store for a race that in practice means
 * one operator clicking twice, or two admins updating at the same moment on
 * a single-server install — not worth the added complexity here.
 */
class HandoffToken
{
    /**
     * Mint a fresh handoff token and persist its hash. Returns the raw token
     * to place in the launch URL; it is not stored anywhere.
     *
     * Overwrites any handoff.json already on disk — see the class docblock's
     * note on single-slot semantics: an earlier unconsumed token is
     * invalidated the moment a new one is minted.
     */
    public function mint(): string
    {
        $token = bin2hex(random_bytes(32));

        $ttl = (int) config('updates.updater.handoff_ttl', 300);

        $payload = [
            'token_hash' => hash('sha256', $token),
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];

        File::ensureDirectoryExists(dirname($this->handoffFile()));

        file_put_contents($this->handoffFile(), json_encode($payload, JSON_PRETTY_PRINT));
        // Only a sha256 hash is stored, never the raw token, but matches the
        // updater's own recovery-token file (chmod 0600) as defense in depth
        // on a shared host where other local users could otherwise read it.
        @chmod($this->handoffFile(), 0600);

        return $token;
    }

    /**
     * Mint a token and build the absolute URL the admin is redirected to.
     */
    public function launchUrl(): string
    {
        $token = $this->mint();

        $path = (string) config('updates.updater.path', 'updater.php');

        // Derive the host from the operator-configured APP_URL, never from the
        // request: request()->root() reflects the client-controlled Host /
        // X-Forwarded-Host headers, and this URL carries the live handoff
        // token. A forged Host would otherwise 302 the token off-site.
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/'.ltrim($path, '/').'?token='.$token;
    }

    /**
     * The file the updater reads. Mirrors the updater's updaterStorageDir()
     * (it reads the same config value), resolved via base_path() rather than
     * storage_path() so a custom Laravel storage path cannot desync the two.
     * Public so tests and operators can assert on it.
     */
    public function handoffFile(): string
    {
        return base_path((string) config('updates.updater.storage_dir', 'storage/app/updater').'/handoff.json');
    }
}
