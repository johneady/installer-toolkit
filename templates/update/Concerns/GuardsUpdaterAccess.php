<?php

/**
 * Token-gated access, with two ways in:
 *
 *  1. Handoff (the normal path): the application's admin panel writes
 *     storage/app/updater/handoff.json — {"token_hash": sha256, "expires_at": ts}
 *     — and redirects the admin here with ?token=… . The token is one-time:
 *     the file is deleted the moment it authorizes a session.
 *
 *  2. Recovery (no handoff present): the updater writes a token to
 *     storage/app/updater/access-token.txt. Only someone with filesystem
 *     access — the operator — can read it, which is the proof. This is what
 *     makes the updater double as the disaster-recovery tool when the app
 *     (and its admin panel) is down.
 */
trait GuardsUpdaterAccess
{
    private function handoffFile(): string
    {
        return $this->updaterStorageDir().'/handoff.json';
    }

    private function recoveryTokenFile(): string
    {
        return $this->updaterStorageDir().'/access-token.txt';
    }

    /**
     * Returns true when the session is authorized; otherwise renders the
     * token gate (or consumes a presented token) and returns false, in which
     * case the caller must stop.
     */
    private function authorizeOrRenderGate(): bool
    {
        if (! empty($_SESSION['updater']['authorized'])) {
            return true;
        }

        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

        if ($token !== '' && $this->consumeToken($token)) {
            session_regenerate_id(true);
            $_SESSION['updater']['authorized'] = true;

            // Redirect so the token never lingers in the address bar or
            // browser history.
            header('Location: '.$this->selfUrl());
            exit;
        }

        if ($token !== '') {
            $this->errors[] = 'That token was not recognized. Tokens are single-use — delete storage/app/updater/access-token.txt and reload this page to mint a fresh one.';
        }

        $this->renderTokenGate();

        return false;
    }

    private function consumeToken(string $token): bool
    {
        // Handoff token from the admin panel.
        $handoffFile = $this->handoffFile();
        if (is_file($handoffFile)) {
            $handoff = json_decode((string) file_get_contents($handoffFile), true);

            if (is_array($handoff)) {
                $hash = (string) ($handoff['token_hash'] ?? '');
                $expiresAt = (int) ($handoff['expires_at'] ?? 0);

                if ($hash !== '' && hash_equals($hash, hash('sha256', $token))) {
                    @unlink($handoffFile);

                    return $expiresAt === 0 || time() <= $expiresAt;
                }
            }
        }

        // Recovery token minted by this script.
        $recoveryFile = $this->recoveryTokenFile();
        if (is_file($recoveryFile)) {
            $expected = trim((string) file_get_contents($recoveryFile));

            if ($expected !== '' && hash_equals($expected, $token)) {
                @unlink($recoveryFile);

                return true;
            }
        }

        return false;
    }

    /**
     * Make sure a recovery token exists for the operator to fetch. Returns
     * whether the token file is in place (it may not be writable on a badly
     * broken install — the gate page explains what to fix).
     */
    private function ensureRecoveryToken(): bool
    {
        $file = $this->recoveryTokenFile();

        if (is_file($file) && trim((string) file_get_contents($file)) !== '') {
            return true;
        }

        $token = bin2hex(random_bytes(32));

        if (@file_put_contents($file, $token) === false) {
            return false;
        }

        @chmod($file, 0600);

        return true;
    }

    private function selfUrl(): string
    {
        return strtok((string) ($_SERVER['REQUEST_URI'] ?? 'updater.php'), '?') ?: 'updater.php';
    }
}
