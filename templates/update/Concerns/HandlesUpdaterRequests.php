<?php

trait HandlesUpdaterRequests
{
    public function run(): void
    {
        // A distinct session name so the updater's session cookie never
        // collides with the application's own session.
        session_name('app_updater');
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
        // happen to live on.
        if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAjax((string) $_GET['ajax']);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost((string) ($_POST['action'] ?? ''));

            return;
        }

        $this->renderCurrentScreen();
    }

    private function renderCurrentScreen(): void
    {
        $state = $_SESSION['updater'];

        // An update run in progress always wins — a mid-update page reload
        // must land back on the progress screen, never on upload.
        if (! empty($state['update']['started']) && empty($state['update']['finished'])) {
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
                $this->handleStartUpdate();
                break;
            case 'cancel-package':
                $this->handleCancelPackage();
                break;
            default:
                $this->renderCurrentScreen();
        }
    }

    /**
     * Transition from the review screen into the update run. All the heavy
     * lifting happens in the progress screen's AJAX task loop; this just
     * pins the run's metadata into the session.
     */
    private function handleStartUpdate(): void
    {
        $package = $_SESSION['updater']['package'] ?? [];

        if (empty($package['valid'])) {
            $this->errors[] = 'No validated update package found. Upload one first.';
            $this->renderHome();

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

    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
