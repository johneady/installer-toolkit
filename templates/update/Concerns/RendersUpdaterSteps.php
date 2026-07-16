<?php

trait RendersUpdaterSteps
{
    // ------------------------------------------------------------------
    // Step 0: token gate
    // ------------------------------------------------------------------

    private function renderTokenGate(): void
    {
        $tokenReady = $this->ensureRecoveryToken();
        $errorsHtml = $this->renderErrors();
        $productName = htmlspecialchars(APP_NAME);

        $tokenHelp = $tokenReady
            ? <<<'HTML'
            <ol style="margin:12px 0 0 20px; font-size:.86rem; line-height:1.9;">
                <li>Open <code>storage/app/updater/access-token.txt</code> in your hosting file manager (or over FTP/SSH).</li>
                <li>Copy its contents and paste the token below.</li>
            </ol>
            <p style="font-size:.78rem; margin-top:10px;" class="d">Only someone with filesystem access to this server can read that file — which is the point. Tokens are single-use.</p>
            HTML
            : <<<'HTML'
            <p class="d" style="font-size:.86rem; margin-top:10px;">The updater could not write its access token to <code>storage/app/updater/</code>. Fix the permissions on <code>storage/app</code> (writable by the web server user), then reload this page.</p>
            HTML;

        $content = <<<HTML
        <p class="h-lede">This is the standalone updater for <strong>{$productName}</strong>. To continue, prove you are the site operator.</p>

        {$errorsHtml}

        <div class="h-alert h-alert-warn" style="display:block;">
            <p class="t" style="margin:0 0 2px;">Access token required</p>
            {$tokenHelp}
        </div>

        <form method="post" action="{$this->selfUrlEscaped()}" style="margin-top:18px;">
            <div class="h-field">
                <label for="token">Access Token</label>
                <input type="text" id="token" name="token" autocomplete="off" spellcheck="false" placeholder="Paste the token from access-token.txt" required>
            </div>
            <div class="h-actions">
                <button type="submit" class="h-btn h-btn-primary" style="margin-left:auto;">Unlock Updater →</button>
            </div>
        </form>
        HTML;

        $this->renderLayout('Updater Access', $content, 0);
    }

    // ------------------------------------------------------------------
    // Step 1: status, history, upload
    // ------------------------------------------------------------------

    private function renderHome(): void
    {
        $version = $this->currentVersion();
        $requirements = $this->checkUpdateRequirements();
        $criticalsPass = true;

        $phpChecks = [];
        $rows = '';
        foreach ($requirements as $r) {
            if (($r['group'] ?? null) === 'php') {
                $phpChecks[] = $r;
            } else {
                $rows .= $this->renderRequirementRow($r);
            }
            if ($r['critical'] && ! $r['passed']) {
                $criticalsPass = false;
            }
        }
        if ($phpChecks !== []) {
            $rows = $this->renderRequirementGroup('PHP', $phpChecks).$rows;
        }

        $versionLabel = $version !== null ? 'v'.htmlspecialchars($version) : 'unknown';
        $historyHtml = $this->renderRecentResults();
        $errorsHtml = $this->renderErrors();

        $uploadSection = $criticalsPass
            ? <<<'HTML'
            <div class="h-alert" id="upload-zone" style="display:block; text-align:center; padding:28px; border-style:dashed; cursor:pointer;">
                <p class="t" style="margin-bottom:4px;">📦 Upload an update package</p>
                <p class="d">Click to choose the <code>.update</code> file you received, or drag it here. It will be uploaded in small chunks, then validated before anything is touched.</p>
                <input type="file" id="update-file" accept=".update" class="h-hidden">
            </div>

            <div id="upload-progress" class="h-progress-card h-hidden">
                <div class="h-progress-card-head">
                    <div class="left">
                        <div class="icn">⬆️</div>
                        <div>
                            <h3>Uploading package…</h3>
                            <p class="sub" id="upload-detail">Starting…</p>
                        </div>
                    </div>
                    <div>
                        <div class="pct" id="upload-percent">0%</div>
                        <div class="pct-label">Uploaded</div>
                    </div>
                </div>
                <div class="h-track"><div id="upload-bar" class="h-track-fill" style="width:0%"></div></div>
            </div>

            <div id="upload-error" class="h-alert h-alert-bad h-hidden">
                <div><p class="t">Upload Failed</p><p class="d" id="upload-error-message"></p></div>
            </div>
            HTML
            : <<<'HTML'
            <div class="h-alert h-alert-bad" style="display:block;">
                <p class="t">Updates are blocked</p>
                <p class="d">Fix the critical requirements above, then reload this page.</p>
            </div>
            HTML;

        $content = <<<HTML
        {$errorsHtml}

        <div class="h-reqrow good" style="margin-bottom:20px;">
            <div class="icon">🏷️</div>
            <div class="body">
                <p class="t">Installed Version</p>
                <p class="d">You are currently running {$versionLabel}.</p>
            </div>
            <span class="badge">{$versionLabel}</span>
        </div>

        <p class="h-section-title">Server Check</p>
        {$rows}

        {$historyHtml}

        <p class="h-section-title" style="margin-top:22px;">Apply an Update</p>
        {$uploadSection}

        <script>
            (function() {
                var zone = document.getElementById('upload-zone');
                if (!zone) return;

                var input = document.getElementById('update-file');
                var CHUNK = 1572864; // 1.5 MB — safely under shared-hosting upload_max_filesize floors

                zone.addEventListener('click', function() { input.click(); });
                zone.addEventListener('dragover', function(e) { e.preventDefault(); });
                zone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (e.dataTransfer.files.length) startUpload(e.dataTransfer.files[0]);
                });
                input.addEventListener('change', function() {
                    if (input.files.length) startUpload(input.files[0]);
                });

                function fail(message) {
                    document.getElementById('upload-progress').classList.add('h-hidden');
                    zone.classList.remove('h-hidden');
                    document.getElementById('upload-error').classList.remove('h-hidden');
                    document.getElementById('upload-error-message').textContent = message;
                }

                function parseJsonResponse(r) {
                    if (!r.ok) {
                        return r.text().then(function(text) {
                            throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 300));
                        });
                    }
                    return r.json();
                }

                function startUpload(file) {
                    if (!/\.update$/i.test(file.name)) {
                        fail('Please choose a .update package file.');
                        return;
                    }

                    document.getElementById('upload-error').classList.add('h-hidden');
                    zone.classList.add('h-hidden');
                    document.getElementById('upload-progress').classList.remove('h-hidden');

                    var totalChunks = Math.max(1, Math.ceil(file.size / CHUNK));
                    var initData = new FormData();
                    initData.append('name', file.name);
                    initData.append('size', file.size);
                    initData.append('total_chunks', totalChunks);

                    fetchWithCsrf('updater.php?ajax=upload-init', { body: initData })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) throw new Error(data.message);
                            sendChunk(file, data.upload_id, 0, totalChunks, false);
                        })
                        .catch(function(err) { fail(err.message); });
                }

                function sendChunk(file, uploadId, index, totalChunks, isRetry) {
                    var start = index * CHUNK;
                    var blob = file.slice(start, Math.min(start + CHUNK, file.size));

                    var form = new FormData();
                    form.append('upload_id', uploadId);
                    form.append('index', index);
                    form.append('chunk', blob, 'chunk');

                    fetchWithCsrf('updater.php?ajax=upload-chunk', { body: form })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            // A definitive server answer (validation failure,
                            // chunk rejected) — never retried, unlike the
                            // transient errors handled in .catch below.
                            if (!data.success) { fail(data.message); return; }

                            var pct = Math.round(((index + 1) / totalChunks) * 100);
                            document.getElementById('upload-percent').textContent = pct + '%';
                            document.getElementById('upload-bar').style.width = pct + '%';
                            document.getElementById('upload-detail').textContent = 'Chunk ' + (index + 1) + ' of ' + totalChunks;

                            if (!data.complete) {
                                sendChunk(file, uploadId, index + 1, totalChunks, false);
                            } else if (data.valid) {
                                document.getElementById('upload-detail').textContent = 'Validated — v' + data.version;
                                window.location = 'updater.php';
                            } else {
                                fail(data.message);
                            }
                        })
                        .catch(function(err) {
                            // Transient failure (network drop, gateway 502,
                            // dropped response): retry this chunk once before
                            // giving up, so a single blip can't kill a
                            // multi-hundred-MB upload. The server treats a
                            // re-send of an already-received chunk as
                            // idempotent, so a lost *response* (chunk actually
                            // landed) is also safe to retry.
                            if (!isRetry) {
                                document.getElementById('upload-detail').textContent = 'Chunk ' + (index + 1) + ' failed — retrying…';
                                setTimeout(function() { sendChunk(file, uploadId, index, totalChunks, true); }, 2000);
                                return;
                            }
                            fail(err.message);
                        });
                }
            })();
        </script>
        HTML;

        $this->renderLayout('System Update', $content, 1);
    }

    private function renderRecentResults(): string
    {
        $files = glob($this->resultsDir().'/*.json') ?: [];
        rsort($files);
        $files = array_slice($files, 0, 5);

        if ($files === []) {
            return '';
        }

        $rows = '';
        foreach ($files as $file) {
            $result = json_decode((string) file_get_contents($file), true);
            if (! is_array($result)) {
                continue;
            }

            $status = (string) ($result['status'] ?? 'unknown');
            $good = $status === 'applied';
            $rowClass = $good ? 'good' : 'warn';
            $icon = $this->statusIcon($good ? 'check' : 'warning', 16);
            $from = htmlspecialchars((string) ($result['version_from'] ?? '?'));
            $to = htmlspecialchars((string) ($result['version_to'] ?? '?'));
            $when = htmlspecialchars((string) ($result['finished_at'] ?? ''));
            $badge = htmlspecialchars(str_replace('_', ' ', $status));

            $rows .= <<<HTML
            <div class="h-reqrow {$rowClass}">
                <div class="icon">{$icon}</div>
                <div class="body">
                    <p class="t">v{$from} → v{$to}</p>
                    <p class="d">{$when}</p>
                </div>
                <span class="badge">{$badge}</span>
            </div>
            HTML;
        }

        if ($rows === '') {
            return '';
        }

        return '<p class="h-section-title" style="margin-top:22px;">Recent Updates</p>'.$rows;
    }

    // ------------------------------------------------------------------
    // Step 2: review the validated package
    // ------------------------------------------------------------------

    private function renderReview(): void
    {
        $package = $_SESSION['updater']['package'];
        $current = htmlspecialchars((string) $this->currentVersion());
        $target = htmlspecialchars((string) $package['version']);
        $filesCount = $package['files_count'] !== null ? number_format((int) $package['files_count']).' files' : 'unknown file count';
        $signedRow = $package['signed_by'] !== null
            ? $this->renderRequirementRow(['name' => 'Signature Verified', 'detail' => "Signed with trusted key '{$package['signed_by']}' (Ed25519).", 'passed' => true, 'critical' => true])
            : $this->renderRequirementRow(['name' => 'Unsigned Package', 'detail' => 'This server does not require signed updates.', 'passed' => false, 'critical' => false]);

        $backupNote = ($this->backupConfig()['enabled'] ?? true)
            ? 'A full backup of your files'.(($this->backupConfig()['database']['enabled'] ?? true) ? ' and database' : '').' is taken first, and can be restored automatically if anything fails.'
            : 'Backups are disabled in this application\'s configuration — a failed update cannot be automatically rolled back.';

        $checkRow = $this->renderRequirementRow(['name' => 'Package Integrity', 'detail' => 'Checksum verified; no unsafe paths found in the archive.', 'passed' => true, 'critical' => true]);

        // The exact staged inner-zip size is known now (unlike at
        // upload-init's rough size-based estimate), so this is a firmer
        // check. The size heuristic lives in diskSpaceShortfall(), shared
        // with the upload gate and the start-update guard.
        $stagedZip = (string) ($package['staged_zip'] ?? '');
        $stagedSize = is_file($stagedZip) ? (int) filesize($stagedZip) : 0;
        $shortfall = $this->diskSpaceShortfall($stagedSize, $this->appRoot());

        $applyAction = $shortfall === null
            ? <<<HTML
            <form method="post" action="{$this->selfUrlEscaped()}" style="margin-left:auto;">
                {$this->csrfField()}
                <input type="hidden" name="action" value="start-update">
                <button type="submit" class="h-btn h-btn-primary">Apply Update →</button>
            </form>
            HTML
            : '';

        $spaceWarning = $shortfall === null ? '' : <<<HTML
            <div class="h-alert h-alert-bad" style="display:block; margin-top:16px;">
                <p class="t">Not enough free disk space</p>
                <p class="d">This update needs roughly {$this->formatBytes($shortfall['needed'])} free (backup + staged files + extraction), but only {$this->formatBytes($shortfall['free'])} is available. Free up space on the server, then reload this page.</p>
            </div>
            HTML;

        $content = <<<HTML
        <p class="h-lede">The uploaded package is valid and ready to apply.</p>

        <div class="h-reqrow good" style="margin-bottom:16px;">
            <div class="icon">🚀</div>
            <div class="body">
                <p class="t">v{$current} → v{$target}</p>
                <p class="d">{$filesCount} will be applied.</p>
            </div>
            <span class="badge">Ready</span>
        </div>

        {$checkRow}
        {$signedRow}

        <div class="h-alert h-alert-warn" style="display:block; margin-top:16px;">
            <p class="t">What happens next</p>
            <p class="d">The site goes into maintenance mode while the update runs. {$backupNote}</p>
        </div>

        {$spaceWarning}

        <div class="h-actions" style="margin-top:18px;">
            <form method="post" action="{$this->selfUrlEscaped()}">
                {$this->csrfField()}
                <input type="hidden" name="action" value="cancel-package">
                <button type="submit" class="h-btn h-btn-ghost">← Cancel</button>
            </form>
            {$applyAction}
        </div>
        HTML;

        $this->renderLayout('Review Update', $content, 2);
    }

    // ------------------------------------------------------------------
    // Step 3: progress
    // ------------------------------------------------------------------

    private function renderProgress(): void
    {
        $tasks = [
            'prepare' => 'Enabling maintenance mode',
            'backup_files' => 'Backing up application files',
            'backup_db' => 'Backing up database',
            'extract' => 'Extracting update files',
            'post_update' => 'Running migrations and refreshing caches',
            'verify' => 'Verifying the new version',
            'finalize' => 'Finalizing and bringing the site back',
        ];

        $update = $_SESSION['updater']['update'];
        $completedTasks = $update['completed_tasks'] ?? [];
        $target = htmlspecialchars((string) ($update['version_to'] ?? ''));

        $taskList = '';
        foreach ($tasks as $key => $label) {
            $status = in_array($key, $completedTasks, true) ? 'done' : 'pending';
            $taskList .= "<div class=\"h-task task-item\" data-task=\"{$key}\" data-status=\"{$status}\">";
            $taskList .= '<span class="icon task-icon"></span>';
            $taskList .= "<span class=\"label task-label\">{$label}</span>";
            $taskList .= '<span class="msg task-message"></span>';
            $taskList .= '</div>';
        }

        $tasksJson = json_encode(array_keys($tasks));
        $xIcon = $this->statusIcon('x');
        $checkIconJson = json_encode($this->statusIcon('check'));
        $xIconJson = json_encode($this->statusIcon('x'));

        $content = <<<HTML
        <p class="h-lede">Updating to <strong>v{$target}</strong>. <strong>Please do not close or refresh this page.</strong></p>

        <div class="h-progress-card">
            <div class="h-progress-card-head">
                <div class="left">
                    <div class="icn">⚡</div>
                    <div>
                        <h3>Update Progress</h3>
                        <p class="sub">Applying v{$target}</p>
                    </div>
                </div>
                <div>
                    <div class="pct" id="progress-percent">0%</div>
                    <div class="pct-label">Complete</div>
                </div>
            </div>
            <div class="h-track"><div id="progress-bar" class="h-track-fill" style="width: 0%"></div></div>
            <div class="h-tasklist" id="task-list">{$taskList}</div>
        </div>

        <div id="update-error" class="h-hidden h-alert h-alert-bad">
            <div class="icon">{$xIcon}</div>
            <div>
                <p class="t">Update Failed</p>
                <p class="d" id="error-message"></p>
            </div>
        </div>

        <div class="h-actions h-hidden" id="update-actions">
            <div class="h-actions-group" style="margin-left:auto;">
                <button type="button" class="h-btn h-btn-ghost h-hidden" id="retry-btn">Retry Step</button>
                <button type="button" class="h-btn h-btn-primary h-hidden" id="restore-btn">Restore Backup</button>
            </div>
        </div>

        <script>
            var tasks = {$tasksJson};
            var currentTaskIndex = 0;

            document.querySelectorAll('.task-item[data-status="done"]').forEach(function(el) {
                setTaskState(el, 'done');
                currentTaskIndex++;
            });

            var SPINNER_SVG = '<svg class="h-spin" width="16" height="16" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

            var TASK_STATE_HTML = {
                pending: '',
                active: SPINNER_SVG,
                done: {$checkIconJson},
                error: {$xIconJson},
            };

            function setTaskState(el, state, message) {
                el.dataset.status = state;
                el.className = 'h-task task-item' + (state !== 'pending' ? ' ' + state : '');
                el.querySelector('.task-icon').innerHTML = TASK_STATE_HTML[state];
                el.querySelector('.task-message').textContent = message || '';
            }

            function updateProgress() {
                var percent = Math.round((currentTaskIndex / tasks.length) * 100);
                document.getElementById('progress-percent').textContent = percent + '%';
                document.getElementById('progress-bar').style.width = percent + '%';
            }

            function parseJsonResponse(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 500));
                    });
                }
                return r.json();
            }

            function handleTaskError(el, message, canRestore) {
                setTaskState(el, 'error', '');
                document.getElementById('update-error').classList.remove('h-hidden');
                document.getElementById('error-message').textContent = message;
                document.getElementById('update-actions').classList.remove('h-hidden');
                document.getElementById('retry-btn').classList.remove('h-hidden');
                if (canRestore) {
                    document.getElementById('restore-btn').classList.remove('h-hidden');
                }
            }

            function runTask(taskName, el) {
                setTaskState(el, 'active');
                fetchWithCsrf('updater.php?ajax=update-task&task=' + taskName)
                    .then(parseJsonResponse)
                    .then(function(data) {
                        if (!data.success) {
                            handleTaskError(el, data.message, !!data.can_restore);
                            return;
                        }
                        if (data.task_done === false) {
                            setTaskState(el, 'active', data.message);
                            runTask(taskName, el);
                            return;
                        }
                        setTaskState(el, 'done', data.message || '');
                        currentTaskIndex++;
                        updateProgress();
                        runNextTask();
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message, false);
                    });
            }

            function runNextTask() {
                if (currentTaskIndex >= tasks.length) {
                    // finalize cleared the run state server-side; reloading
                    // lands on the Complete screen.
                    window.location = 'updater.php';
                    return;
                }

                var task = tasks[currentTaskIndex];
                runTask(task, document.querySelector('.task-item[data-task="' + task + '"]'));
            }

            document.getElementById('retry-btn').addEventListener('click', function() {
                document.getElementById('update-error').classList.add('h-hidden');
                document.getElementById('update-actions').classList.add('h-hidden');
                document.getElementById('retry-btn').classList.add('h-hidden');
                document.getElementById('restore-btn').classList.add('h-hidden');
                runNextTask();
            });

            document.getElementById('restore-btn').addEventListener('click', function() {
                document.getElementById('update-error').classList.add('h-hidden');
                document.getElementById('update-actions').classList.add('h-hidden');

                var el = document.querySelector('.task-item[data-status="error"]') || document.querySelector('.task-item');
                setTaskState(el, 'active', 'Restoring backup…');

                (function restoreLoop() {
                    fetchWithCsrf('updater.php?ajax=update-task&task=restore')
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) {
                                handleTaskError(el, 'Restore failed: ' + data.message, false);
                                return;
                            }
                            setTaskState(el, 'active', data.message);
                            if (data.task_done === false) {
                                restoreLoop();
                            } else {
                                window.location = 'updater.php?screen=home';
                            }
                        })
                        .catch(function(err) {
                            handleTaskError(el, 'Restore failed: ' + err.message, false);
                        });
                })();
            });

            updateProgress();
            runNextTask();
        </script>
        HTML;

        $this->renderLayout('Updating', $content, 3);
    }

    // ------------------------------------------------------------------
    // Step 4: complete
    // ------------------------------------------------------------------

    private function renderComplete(): void
    {
        $done = $_SESSION['updater']['done'] ?? [];
        $to = htmlspecialchars((string) ($done['version'] ?? ''));
        $from = htmlspecialchars((string) ($done['version_from'] ?? ''));
        $productName = htmlspecialchars(APP_NAME);

        $content = <<<HTML
        <div class="h-success-head">
            <div class="h-success-icon">🎉</div>
            <h2 style="margin:8px 0 4px;">Update Complete!</h2>
            <p class="h-lede">{$productName} was updated from v{$from} to <strong>v{$to}</strong> and is live again.</p>
        </div>

        <div class="h-alert h-alert-good" style="display:block; margin-top:12px;">
            <p class="t">The updater refreshed itself too</p>
            <p class="d">The version of this updater that shipped inside the package is now in place for next time. Your pre-update backup is kept in <code>storage/app/updater/backups/</code> until newer backups rotate it out.</p>
        </div>

        <div class="h-actions" style="margin-top:20px;">
            <a href="updater.php?screen=home" class="h-btn h-btn-ghost">Run Another Update</a>
            <a href="/" class="h-btn h-btn-primary" style="margin-left:auto;">Go to Site →</a>
        </div>
        HTML;

        $this->renderLayout('Complete', $content, 4);
    }
}
