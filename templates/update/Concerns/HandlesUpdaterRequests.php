<?php

trait HandlesUpdaterRequests
{
    public function run(): void
    {
        // Most shared hosts allow overriding this per-request even when the
        // php.ini default is low; @-suppressed since it's a no-op (not an
        // error) when disabled via disable_functions. Covers every request
        // this tool handles — chunk upload assembly/validation, backup,
        // extraction, and restore are all slow enough to risk the default.
        @set_time_limit(300);

        // A distinct session name so the updater's session cookie never
        // collides with the application's own session.
        session_name('app_updater');
        applyHardenedSessionCookieParams();
        session_start();

        if (! isset($_SESSION['updater'])) {
            $_SESSION['updater'] = [];
        }

        $this->ensureUpdaterDirs();
        $this->loadEnvIntoStore();

        if (! $this->authorizeOrRenderGate()) {
            return;
        }

        // AJAX sub-actions are routed by name, not by which screen they
        // happen to live on. CSRF is only checked once authorized — the
        // token-gate form above submits the operator's filesystem-proof
        // token itself, which an attacker riding the session cookie
        // wouldn't have anyway.
        if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrfToken();
            $this->handleAjax((string) $_GET['ajax']);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrfToken();
            $this->handlePost((string) ($_POST['action'] ?? ''));

            return;
        }

        $this->renderCurrentScreen();
    }

    /**
     * Whether an update run has started and not yet finished. Shared by
     * renderCurrentScreen() (so a mid-update page reload always lands back
     * on the progress screen, never on upload) and by the upload/start-update
     * handlers (so a second tab, or a hand-crafted request from the same
     * authorized session, can't kick off a new upload or run while one is
     * already in flight — see handleUploadInit()'s docblock).
     */
    private function updateRunInProgress(): bool
    {
        $update = $_SESSION['updater']['update'] ?? [];

        return ! empty($update['started']) && empty($update['finished']);
    }

    private function renderCurrentScreen(): void
    {
        $state = $_SESSION['updater'];

        // An update run in progress always wins — a mid-update page reload
        // must land back on the progress screen, never on upload.
        if ($this->updateRunInProgress()) {
            $this->renderProgress();

            return;
        }

        if (($_GET['screen'] ?? '') === 'home') {
            unset($_SESSION['updater']['done']);
            $this->renderHome();

            return;
        }

        if (! empty($state['done'])) {
            $this->renderComplete();

            return;
        }

        if (! empty($state['package']['valid'])) {
            $this->renderReview();

            return;
        }

        $this->renderHome();
    }

    private function handleAjax(string $action): void
    {
        switch ($action) {
            case 'upload-init':
                $this->handleUploadInit();
                break;
            case 'upload-chunk':
                $this->handleUploadChunk();
                break;
            case 'update-task':
                $this->handleUpdateTask((string) ($_GET['task'] ?? ''));
                break;
            default:
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown AJAX action.']);
                exit;
        }
    }

    private function handlePost(string $action): void
    {
        switch ($action) {
            case 'start-update':
            case 'cancel-package':
                // Both mutate or delete $_SESSION['updater']['package']/
                // ['update'] state that an in-flight run depends on —
                // start-update would clobber the run already underway,
                // and cancel-package would delete the staged zip
                // taskExtract() is actively reading (see
                // updateRunInProgress()'s docblock). The UI can't reach
                // either action once a run has started
                // (renderCurrentScreen() always shows the progress screen
                // instead), so this only guards against a second tab or a
                // hand-crafted direct request. Checked once here, at the
                // dispatch choke point, rather than in each handler, so a
                // future state-changing POST action added to this switch
                // can't forget it.
                if ($this->updateRunInProgress()) {
                    $this->renderProgress();

                    return;
                }

                $action === 'start-update' ? $this->handleStartUpdate() : $this->handleCancelPackage();
                break;
            default:
                $this->renderCurrentScreen();
        }
    }

    /**
     * Transition from the review screen into the update run. All the heavy
     * lifting happens in the progress screen's AJAX task loop; this just
     * pins the run's metadata into the session. The in-progress guard lives
     * in handlePost() (see its docblock), not here.
     */
    private function handleStartUpdate(): void
    {
        $package = $_SESSION['updater']['package'] ?? [];

        if (empty($package['valid'])) {
            $this->errors[] = 'No validated update package found. Upload one first.';
            $this->renderHome();

            return;
        }

        // The review screen already hides the "Apply Update" button when
        // space is short, but that's client-side only — re-check here so a
        // resubmitted or hand-crafted request can't start a run that will
        // run out of disk partway through backup or extraction. (The size
        // heuristic lives in diskSpaceShortfall(), shared with the upload
        // gate and the review screen.)
        $stagedZip = (string) ($package['staged_zip'] ?? '');
        $stagedSize = is_file($stagedZip) ? (int) filesize($stagedZip) : 0;

        if ($shortfall = $this->diskSpaceShortfall($stagedSize, $this->appRoot())) {
            $this->errors[] = 'Not enough free disk space to safely apply this update (needs roughly '.$this->formatBytes($shortfall['needed']).', '.$this->formatBytes($shortfall['free']).' available).';
            $this->renderReview();

            return;
        }

        $_SESSION['updater']['update'] = [
            'started' => true,
            'finished' => false,
            'started_at' => date('c'),
            'version_from' => $this->currentVersion(),
            'version_to' => $package['version'],
            'completed_tasks' => [],
        ];

        header('Location: '.$this->selfUrl());
        exit;
    }

    private function handleCancelPackage(): void
    {
        $package = $_SESSION['updater']['package'] ?? [];

        foreach (['path', 'staged_zip'] as $key) {
            if (! empty($package[$key]) && file_exists($package[$key])) {
                @unlink($package[$key]);
            }
        }

        unset($_SESSION['updater']['package'], $_SESSION['updater']['upload']);

        header('Location: '.$this->selfUrl());
        exit;
    }

    /**
     * Every authorized state-changing POST (form submission or AJAX call)
     * must carry the per-session token minted by csrfToken() and rendered
     * into forms/window.CSRF_TOKEN by renderLayout() — otherwise a
     * cross-site page could ride the authorized updater session cookie
     * into e.g. start-update or update-task=restore.
     */
    private function requireValidCsrfToken(): void
    {
        if (csrfTokenValid('csrf_token')) {
            return;
        }

        if (isset($_GET['ajax'])) {
            $this->jsonResponse(['success' => false, 'message' => 'Your session has expired or this request could not be verified. Please reload the page and try again.']);
        }

        $this->errors[] = 'Your session has expired or this request could not be verified. Please reload the page and try again.';
        $this->renderCurrentScreen();
        exit;
    }

    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
