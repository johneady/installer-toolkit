<?php

trait RendersSteps
{
    // ========================================================================
    // Step Renderers
    // ========================================================================

    private function renderStep(int $step): void
    {
        switch ($step) {
            case 0:
                $this->renderWelcome();
                break;
            case 1:
                $this->renderEula();
                break;
            case 2:
                $this->renderRequirements();
                break;
            case 3:
                $this->renderDatabase();
                break;
            case 4:
                $this->renderSettings();
                break;
            case 5:
                $this->renderEmail();
                break;
            case 6:
                $this->renderAdmin();
                break;
            case 7:
                $this->renderInstall();
                break;
            case 8:
                $this->renderCron();
                break;
            case 9:
                $this->renderComplete();
                break;
        }
    }

    private function renderWelcome(): void
    {
        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));

        $content = <<<HTML
        <!-- Welcome Icon -->
        <div style="display:flex; justify-content:center;">
            <div class="h-success-icon" style="background:var(--h-accent); box-shadow:0 12px 30px -10px rgba(var(--h-shadow-accent), .5);">
                <span style="font-size:32px;">🎉</span>
            </div>
        </div>

        <!-- Welcome Message -->
        <div class="h-success-head">
            <h2 style="color:var(--h-ink);">Thank You for Your Purchase!</h2>
            <p>We're thrilled to have you on board. Let's get {$appName} up and running on your server.</p>
        </div>

        <p class="h-lede" style="text-align:center; max-width:480px; margin-left:auto; margin-right:auto;">
            This wizard will walk you through just a few quick steps — reviewing the license, checking your server's requirements, connecting your database, configuring your application, and creating your admin account. It usually takes about 5 minutes.
        </p>

        <!-- Actions -->
        <div class="h-actions end" style="border-top:none; padding-top:0;">
            <a href="install.php?step=1" class="h-btn h-btn-primary">Get Started →</a>
        </div>
HTML;

        $this->renderLayout('Welcome', $content, 0);
    }

    private function renderEula(): void
    {
        $errors = $this->renderErrors();
        $eula = htmlspecialchars(EULA_TEXT);

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=1">
            <!-- EULA Box -->
            <div class="h-eula">
                <div class="h-eula-head">
                    <span class="icon">⚖️</span>
                    <div>
                        <h3>End User License Agreement</h3>
                        <p>Please read carefully before proceeding</p>
                    </div>
                </div>
                <div class="h-eula-body">{$eula}</div>
            </div>

            <!-- Checkbox -->
            <label class="h-checkline" for="accept-eula" style="cursor:pointer;">
                <input type="checkbox" name="accept_eula" value="1" id="accept-eula">
                <div>
                    <span class="t">I have read and agree to the End User License Agreement</span>
                    <p class="d">By checking this box, you confirm that you understand and accept all terms</p>
                </div>
            </label>

            <!-- Actions -->
            <div class="h-actions end">
                <button type="submit" class="h-btn h-btn-primary" id="accept-btn" disabled>I Accept →</button>
            </div>
        </form>
        <script>
            document.getElementById('accept-eula').addEventListener('change', function() {
                document.getElementById('accept-btn').disabled = !this.checked;
            });
        </script>
HTML;

        $this->renderLayout('License Agreement', $content, 1);
    }

    private function renderRequirements(): void
    {
        $results = $this->checkRequirements();
        $allCriticalPassed = true;
        foreach ($results as $r) {
            if (! $r['passed'] && $r['critical']) {
                $allCriticalPassed = false;
            }
        }

        // Only surface individual detail cards for checks that need attention.
        // Passing checks are collapsed into a single summary banner to save space.
        $passed = array_filter($results, fn ($r) => $r['passed']);
        $notPassed = array_filter($results, fn ($r) => ! $r['passed']);

        $items = '';
        if (! empty($passed)) {
            $checkIcon = $this->statusIcon('check', 16);
            $items .= "<div class=\"h-reqrow good\">
                <div class=\"icon\">
                    {$checkIcon}
                </div>
                <div class=\"body\">
                    <p class=\"t\">".count($passed).' requirement'.(count($passed) === 1 ? '' : 's').' met</p>
                    <p class="d">'.implode(', ', array_map(fn ($r) => $r['name'], $passed)).'</p>
                </div>
                <span class="badge">Passed</span>
            </div>';
        }
        foreach ($notPassed as $r) {
            $icon = $this->statusIcon($r['critical'] ? 'x' : 'warning', 16);
            $rowClass = $r['critical'] ? 'bad' : 'warn';
            $items .= "<div class=\"h-reqrow {$rowClass}\">
                <div class=\"icon\">
                    {$icon}
                </div>
                <div class=\"body\">
                    <p class=\"t\">{$r['name']}</p>
                    <p class=\"d\">{$r['detail']}</p>
                </div>
                <span class=\"badge\">".($r['critical'] ? 'Critical' : 'Warning').'</span>
            </div>';
        }

        $disabled = $allCriticalPassed ? '' : 'disabled';
        $retestButton = $allCriticalPassed ? '' : '<button type="button" class="h-btn h-btn-tint" onclick="window.location.href=\'install.php?step=2\'">Re-Test</button>';
        $warning = $allCriticalPassed ? '' : '<div class="h-alert h-alert-bad">
            <div class="icon">
                '.$this->statusIcon('warning', 16).'
            </div>
            <div>
                <p class="t">Requirements Not Met</p>
                <p class="d">Some critical requirements are not met. Please resolve them before continuing.</p>
            </div>
        </div>';

        $content = <<<HTML
        {$warning}
        <form method="POST" action="install.php?step=2">
        <div>{$items}</div>
        <div class="h-actions">
            <a href="install.php?step=1" class="h-btn h-btn-ghost">← Back</a>
            <div class="h-actions-group">
                {$retestButton}
                <button type="submit" class="h-btn h-btn-primary" {$disabled}>Continue →</button>
            </div>
        </div>
        </form>
HTML;

        $this->renderLayout('Server Requirements', $content, 2);
    }

    private function renderDatabase(): void
    {
        $errors = $this->renderErrors();
        $db = $_SESSION['installer']['db'] ?? [];

        $host = htmlspecialchars($db['host'] ?? 'localhost');
        $port = htmlspecialchars($db['port'] ?? '3306');
        $name = htmlspecialchars($db['name'] ?? '');
        $user = htmlspecialchars($db['user'] ?? '');
        $pass = htmlspecialchars($db['pass'] ?? '');

        $content = <<<HTML
        {$errors}
        <div id="db-test-result" class="h-hidden"></div>
        <form method="POST" action="install.php?step=3" id="db-form">
            <!-- Database Configuration Card -->
            <div class="h-card" style="padding:0; margin-bottom:20px;">
                <div class="h-card-header" style="margin:0; border-radius:22px 22px 0 0;">
                    <span class="icon">🗄️</span>
                    <div>
                        <h3>MySQL Database Connection</h3>
                        <p>Provide your database credentials</p>
                    </div>
                </div>
                <div style="padding:24px;">
                    <div class="h-grid2">
                        <div class="h-field">
                            <label>Database Host <span class="req">*</span></label>
                            <input type="text" name="db_host" id="db_host" value="{$host}" required>
                        </div>
                        <div class="h-field">
                            <label>Database Port</label>
                            <input type="text" name="db_port" id="db_port" value="{$port}">
                        </div>
                        <div class="h-field">
                            <label>Database Name <span class="req">*</span></label>
                            <input type="text" name="db_name" id="db_name" value="{$name}" required>
                        </div>
                        <div class="h-field">
                            <label>Database Username <span class="req">*</span></label>
                            <input type="text" name="db_user" id="db_user" value="{$user}" required>
                        </div>
                    </div>
                    <div class="h-field">
                        <label>Database Password</label>
                        <input type="password" name="db_pass" id="db_pass" value="{$pass}">
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="h-actions">
                <a href="install.php?step=2" class="h-btn h-btn-ghost">← Back</a>
                <div class="h-actions-group">
                    <button type="button" class="h-btn h-btn-tint" id="test-db-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="h-btn h-btn-primary">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('test-db-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('db-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="h-spin">⏳</span> Testing...';
                resultDiv.classList.add('h-hidden');

                var formData = new FormData(document.getElementById('db-form'));

                fetch('install.php?step=3&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('h-hidden');
                    resultDiv.className = data.success ? 'h-alert h-alert-good' : 'h-alert h-alert-bad';
                    resultDiv.innerHTML = '<div class="icon">' + (data.success ? window.INSTALLER_ICONS.check : window.INSTALLER_ICONS.x) + '</div>' +
                        '<div><p class="t">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="d">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('h-hidden');
                    resultDiv.className = 'h-alert h-alert-bad';
                    resultDiv.innerHTML = '<div class="icon">' + window.INSTALLER_ICONS.x + '</div><div><p class="t">Error</p><p class="d">An error occurred while testing the connection.</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                });
            });
        </script>
HTML;

        $this->renderLayout('Database Configuration', $content, 3);
    }

    private function renderSettings(): void
    {
        $errors = $this->renderErrors();
        $s = $_SESSION['installer']['settings'] ?? [];

        $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $defaultUrl = $protocol.'://'.$host;

        $appName = htmlspecialchars($s['app_name'] ?? ucwords(str_replace(['-', '_'], ' ', APP_FOLDER)));
        $appUrl = htmlspecialchars($s['app_url'] ?? $defaultUrl);
        $timezone = $s['timezone'] ?? 'America/Toronto';
        $sampleData = $s['sample_data'] ?? 'essential';
        $essentialChecked = $sampleData === 'essential' ? ' checked' : '';
        $fullChecked = $sampleData === 'full' ? ' checked' : '';

        $timezones = DateTimeZone::listIdentifiers();
        $tzOptions = '';
        foreach ($timezones as $tz) {
            $selected = ($tz === $timezone) ? ' selected' : '';
            $tzOptions .= "<option value=\"{$tz}\"{$selected}>{$tz}</option>";
        }

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=4">
            <!-- Application Settings -->
            <div style="margin-bottom:32px;">
                <h3 class="h-section-title"><span class="bump"></span>Application</h3>
                <div class="h-grid2">
                    <div class="h-field">
                        <label>Application Name <span class="req">*</span></label>
                        <input type="text" name="app_name" id="app_name" value="{$appName}" required>
                    </div>
                    <div class="h-field">
                        <label>Application URL <span class="req">*</span></label>
                        <input type="url" name="app_url" id="app_url" value="{$appUrl}" required>
                    </div>
                </div>
                <div class="h-field">
                    <label>Timezone <span class="req">*</span></label>
                    <select name="timezone" id="timezone" required>{$tzOptions}</select>
                </div>
            </div>

            <!-- Initial Data -->
            <div>
                <h3 class="h-section-title"><span class="bump"></span>Initial Data</h3>
                <p class="h-lede" style="margin-top:-6px;">Choose how much content to pre-populate your site with. You can always add your own data later.</p>
                <label class="h-radio">
                    <input type="radio" name="sample_data" value="essential"{$essentialChecked}>
                    <div>
                        <span class="t">Essentials only</span>
                        <p class="d">Sets up core configuration, menus, and pages — a clean slate ready for your own affiliates and content.</p>
                    </div>
                </label>
                <label class="h-radio">
                    <input type="radio" name="sample_data" value="full"{$fullChecked}>
                    <div>
                        <span class="t">Full demonstration data</span>
                        <p class="d">Includes sample affiliates, applications, blog posts, and more — ideal for exploring all features before going live.</p>
                    </div>
                </label>
            </div>

            <!-- Actions -->
            <div class="h-actions end">
                <a href="install.php?step=3" class="h-btn h-btn-ghost">← Back</a>
                <button type="submit" class="h-btn h-btn-primary">Continue →</button>
            </div>
        </form>
HTML;

        $this->renderLayout('Application Settings', $content, 4);
    }

    private function renderEmail(): void
    {
        $errors = $this->renderErrors();
        $m = $_SESSION['installer']['mail'] ?? [];

        $mailMailer = $m['mail_mailer'] ?? 'log';
        $mailHost = htmlspecialchars($m['mail_host'] ?? '');
        $mailPort = htmlspecialchars($m['mail_port'] ?? '587');
        $mailUsername = htmlspecialchars($m['mail_username'] ?? '');
        $mailPassword = htmlspecialchars($m['mail_password'] ?? '');
        $mailFromAddress = htmlspecialchars($m['mail_from_address'] ?? '');
        $mailFromName = htmlspecialchars($m['mail_from_name'] ?? '');
        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));

        $smtpSelected = $mailMailer === 'smtp' ? ' selected' : '';
        $sendmailSelected = $mailMailer === 'sendmail' ? ' selected' : '';
        $logSelected = $mailMailer === 'log' ? ' selected' : '';
        $smtpDisplay = $mailMailer === 'smtp' ? '' : 'h-hidden';
        $sendmailDisplay = $mailMailer === 'sendmail' ? '' : 'h-hidden';
        $fromDisplay = $mailMailer === 'log' ? 'h-hidden' : '';

        $content = <<<HTML
        {$errors}
        <div id="mail-test-result" class="h-hidden"></div>
        <form method="POST" action="install.php?step=5" id="mail-form">
            <!-- Mail Configuration -->
            <div class="h-card" style="padding:0; margin-bottom:20px;">
                <div class="h-card-header" style="margin:0; border-radius:22px 22px 0 0;">
                    <span class="icon">✉️</span>
                    <div>
                        <h3>Email Configuration</h3>
                        <p>Set up how this application sends email notifications</p>
                    </div>
                </div>
                <div style="padding:24px;">
                    <div class="h-field">
                        <label>Mail Driver</label>
                        <select name="mail_mailer" id="mail_mailer">
                            <option value="smtp"{$smtpSelected}>SMTP</option>
                            <option value="sendmail"{$sendmailSelected}>Sendmail (PHP mail)</option>
                            <option value="log"{$logSelected}>Log (no emails sent)</option>
                        </select>
                        <p class="hint">Select "Log" if you want to configure email later.</p>
                    </div>

                    <!-- SMTP Fields -->
                    <div id="smtp-fields" class="{$smtpDisplay}">
                        <div class="h-grid2">
                            <div class="h-field">
                                <label>SMTP Host</label>
                                <input type="text" name="mail_host" id="mail_host" value="{$mailHost}" placeholder="smtp.example.com">
                            </div>
                            <div class="h-field">
                                <label>SMTP Port</label>
                                <input type="text" name="mail_port" id="mail_port" value="{$mailPort}" placeholder="587">
                            </div>
                            <div class="h-field">
                                <label>SMTP Username</label>
                                <input type="text" name="mail_username" id="mail_username" value="{$mailUsername}">
                            </div>
                            <div class="h-field">
                                <label>SMTP Password</label>
                                <input type="password" name="mail_password" id="mail_password" value="{$mailPassword}">
                            </div>
                        </div>
                    </div>

                    <!-- Sendmail Info -->
                    <div id="sendmail-fields" class="{$sendmailDisplay}">
                        <div class="h-alert h-alert-good" style="margin-bottom:0;">
                            <div>
                                <p class="d" style="color:var(--h-ink);">Uses your server's built-in sendmail/PHP mail function. No additional server configuration needed.</p>
                            </div>
                        </div>
                    </div>

                    <!-- From Fields -->
                    <div id="from-fields" class="{$fromDisplay} h-grid2" style="margin-top:20px;">
                        <div class="h-field">
                            <label>From Address</label>
                            <input type="email" name="mail_from_address" id="mail_from_address" value="{$mailFromAddress}" placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="h-field">
                            <label>From Name</label>
                            <input type="text" name="mail_from_name" id="mail_from_name" value="{$mailFromName}" placeholder="{$appName}">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="h-actions">
                <a href="install.php?step=4" class="h-btn h-btn-ghost">← Back</a>
                <div class="h-actions-group">
                    <button type="button" class="h-btn h-btn-tint" id="test-mail-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="h-btn h-btn-primary">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('mail_mailer').addEventListener('change', function() {
                document.getElementById('smtp-fields').classList.toggle('h-hidden', this.value !== 'smtp');
                document.getElementById('sendmail-fields').classList.toggle('h-hidden', this.value !== 'sendmail');
                document.getElementById('from-fields').classList.toggle('h-hidden', this.value === 'log');
                document.getElementById('mail-test-result').classList.add('h-hidden');
            });

            document.getElementById('test-mail-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('mail-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="h-spin">⏳</span> Testing...';
                resultDiv.classList.add('h-hidden');

                var formData = new FormData(document.getElementById('mail-form'));

                fetch('install.php?step=5&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('h-hidden');
                    resultDiv.className = data.success ? 'h-alert h-alert-good' : 'h-alert h-alert-bad';
                    resultDiv.innerHTML = '<div class="icon">' + (data.success ? window.INSTALLER_ICONS.check : window.INSTALLER_ICONS.x) + '</div>' +
                        '<div><p class="t">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="d">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('h-hidden');
                    resultDiv.className = 'h-alert h-alert-bad';
                    resultDiv.innerHTML = '<div class="icon">' + window.INSTALLER_ICONS.x + '</div><div><p class="t">Error</p><p class="d">An error occurred while testing the mail connection.</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                });
            });
        </script>
HTML;

        $this->renderLayout('Email Configuration', $content, 5);
    }

    private function renderAdmin(): void
    {
        $errors = $this->renderErrors();
        $admin = $_SESSION['installer']['admin'] ?? [];

        $firstName = htmlspecialchars($admin['first_name'] ?? '');
        $lastName = htmlspecialchars($admin['last_name'] ?? '');
        $email = htmlspecialchars($admin['email'] ?? '');

        $content = <<<HTML
        {$errors}
        <p class="h-lede">Create your administrator account. You will use these credentials to log into the admin panel.</p>
        <form method="POST" action="install.php?step=6">
            <!-- Admin Account Card -->
            <div class="h-card" style="padding:0; margin-bottom:20px;">
                <div class="h-card-header" style="margin:0; border-radius:22px 22px 0 0;">
                    <span class="icon">👤</span>
                    <div>
                        <h3>Administrator Account</h3>
                        <p>Set up your admin credentials</p>
                    </div>
                </div>
                <div style="padding:24px;">
                    <div class="h-grid2">
                        <div class="h-field">
                            <label>First Name <span class="req">*</span></label>
                            <input type="text" name="admin_first_name" id="admin_first_name" value="{$firstName}" required>
                        </div>
                        <div class="h-field">
                            <label>Last Name <span class="req">*</span></label>
                            <input type="text" name="admin_last_name" id="admin_last_name" value="{$lastName}" required>
                        </div>
                    </div>
                    <div class="h-field">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="admin_email" id="admin_email" value="{$email}" required>
                    </div>
                    <div class="h-grid2">
                        <div class="h-field">
                            <label>Password <span class="req">*</span></label>
                            <input type="password" name="admin_password" id="admin_password" minlength="8" required>
                            <div class="h-strength-bars" id="password-strength-bars"></div>
                            <p class="hint" id="password-strength-label">Minimum 8 characters</p>
                        </div>
                        <div class="h-field">
                            <label>Confirm Password <span class="req">*</span></label>
                            <input type="password" name="admin_password_confirm" id="admin_password_confirm" minlength="8" required>
                            <p class="h-match-msg h-hidden" id="password-match-message"></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="h-actions">
                <a href="install.php?step=5" class="h-btn h-btn-ghost">← Back</a>
                <button type="submit" class="h-btn h-btn-primary" id="admin-submit-btn">Continue →</button>
            </div>
        </form>
        <script>
            (function() {
                var passwordInput = document.getElementById('admin_password');
                var confirmInput = document.getElementById('admin_password_confirm');
                var strengthBars = document.getElementById('password-strength-bars');
                var strengthLabel = document.getElementById('password-strength-label');
                var matchMessage = document.getElementById('password-match-message');
                var form = passwordInput.closest('form');

                var barColors = ['var(--h-bad)', 'var(--h-warn)', 'var(--h-warn)', 'var(--h-good)'];
                for (var i = 0; i < 4; i++) {
                    strengthBars.appendChild(document.createElement('div'));
                }
                var bars = strengthBars.querySelectorAll('div');

                function scorePassword(value) {
                    if (!value) return 0;
                    var score = 0;
                    if (value.length >= 8) score++;
                    if (value.length >= 12) score++;
                    if (/[0-9]/.test(value) && /[a-zA-Z]/.test(value)) score++;
                    if (/[^a-zA-Z0-9]/.test(value)) score++;
                    return Math.min(score, 4);
                }

                function updateStrength() {
                    var value = passwordInput.value;
                    var score = scorePassword(value);

                    bars.forEach(function(bar, i) {
                        bar.style.background = i < score ? barColors[score - 1] : '';
                    });

                    if (!value) {
                        strengthLabel.textContent = 'Minimum 8 characters';
                        strengthLabel.style.color = '';
                    } else {
                        var labels = ['Too short', 'Weak', 'Okay', 'Good', 'Strong'];
                        strengthLabel.textContent = labels[score];
                        strengthLabel.style.color = barColors[score - 1] || barColors[0];
                    }
                }

                function updateMatch() {
                    if (!confirmInput.value) {
                        matchMessage.classList.add('h-hidden');
                        return true;
                    }
                    matchMessage.classList.remove('h-hidden');
                    if (passwordInput.value === confirmInput.value) {
                        matchMessage.innerHTML = window.INSTALLER_ICONS.checkSmall + ' Passwords match';
                        matchMessage.className = 'h-match-msg good';
                        return true;
                    }
                    matchMessage.innerHTML = window.INSTALLER_ICONS.xSmall + ' Passwords do not match';
                    matchMessage.className = 'h-match-msg bad';
                    return false;
                }

                passwordInput.addEventListener('input', function() {
                    updateStrength();
                    updateMatch();
                });
                confirmInput.addEventListener('input', updateMatch);

                form.addEventListener('submit', function(e) {
                    if (!updateMatch()) {
                        e.preventDefault();
                        confirmInput.focus();
                    }
                });
            })();
        </script>
HTML;

        $this->renderLayout('Admin Account', $content, 6);
    }

    private function renderInstall(): void
    {
        $tasks = [
            'extract' => 'Extracting application files',
            'bootstrap_files' => 'Configuring web server and environment',
            'migrate' => 'Running database migrations',
            'seed' => 'Seeding database with initial data',
            'optimize' => 'Optimizing application',
        ];

        $completedTasks = $_SESSION['installer']['completed_tasks'] ?? [];

        $taskList = '';
        foreach ($tasks as $key => $label) {
            $status = in_array($key, $completedTasks) ? 'done' : 'pending';
            $taskList .= "<div class=\"h-task task-item\" data-task=\"{$key}\" data-status=\"{$status}\">";
            $taskList .= '<span class="icon task-icon"></span>';
            $taskList .= "<span class=\"label task-label\">{$label}</span>";
            $taskList .= '<span class="msg task-message"></span>';
            $taskList .= '</div>';
        }

        $tasksJson = json_encode(array_keys($tasks));

        if (! isset($_SESSION['installer']['optimize_token'])) {
            $_SESSION['installer']['optimize_token'] = bin2hex(random_bytes(32));
        }

        $optimizeToken = $_SESSION['installer']['optimize_token'];

        $cleanProcessTasks = json_encode([
            'optimize' => 'config:clear,package:discover,config:cache,event:cache,route:cache,view:cache,icons:cache,filament:optimize',
        ]);

        $appFolder = APP_FOLDER;
        $xIcon = $this->statusIcon('x');
        $checkIconJson = json_encode($this->statusIcon('check'));
        $xIconJson = json_encode($this->statusIcon('x'));

        $content = <<<HTML
        <p class="h-lede">Installing your application. Please do not close this page.</p>

        <!-- Progress Card -->
        <div class="h-progress-card">
            <div class="h-progress-card-head">
                <div class="left">
                    <div class="icn">⚡</div>
                    <div>
                        <h3>Installation Progress</h3>
                        <p class="sub">Step 7 of 9</p>
                    </div>
                </div>
                <div>
                    <div class="pct" id="progress-percent">0%</div>
                    <div class="pct-label">Complete</div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="h-track">
                <div id="progress-bar" class="h-track-fill" style="width: 0%"></div>
            </div>

            <!-- Task List -->
            <div class="h-tasklist" id="task-list">{$taskList}</div>
        </div>

        <div id="install-error" class="h-hidden h-alert h-alert-bad">
            <div class="icon">
                {$xIcon}
            </div>
            <div>
                <p class="t">Installation Failed</p>
                <p class="d" id="error-message"></p>
            </div>
        </div>

        <div class="h-actions h-hidden" id="install-actions">
            <a href="install.php?step=3" class="h-btn h-btn-ghost h-hidden" id="start-over-btn">← Start Over</a>
            <div class="h-actions-group" style="margin-left:auto;">
                <button type="button" class="h-btn h-btn-ghost h-hidden" id="retry-btn">Retry</button>
                <a href="install.php?step=8" class="h-btn h-btn-primary h-hidden" id="continue-btn">Continue →</a>
            </div>
        </div>
        <script>
            var tasks = {$tasksJson};
            var cleanProcessTasks = {$cleanProcessTasks};
            var optimizeToken = '{$optimizeToken}';
            var currentTaskIndex = 0;

            document.querySelectorAll('.task-item[data-status="done"]').forEach(function(el) {
                setTaskState(el, 'done');
                currentTaskIndex++;
            });

            function updateProgress(percent) {
                document.getElementById('progress-percent').textContent = Math.round(percent) + '%';
                document.getElementById('progress-bar').style.width = percent + '%';
            }

            var SPINNER_SVG = '<svg class="h-spin" width="16" height="16" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

            var TASK_STATE_HTML = {
                pending: '',
                active: SPINNER_SVG,
                done: {$checkIconJson},
                error: {$xIconJson},
            };

            function setTaskState(el, state, message) {
                var icon = el.querySelector('.task-icon');

                el.dataset.status = state;
                el.className = 'h-task task-item' + (state !== 'pending' ? ' ' + state : '');

                icon.innerHTML = TASK_STATE_HTML[state];

                el.querySelector('.task-message').textContent = message || '';
            }

            function runTasks(fullReset) {
                document.getElementById('install-error').classList.add('h-hidden');
                document.getElementById('retry-btn').classList.add('h-hidden');
                document.getElementById('start-over-btn').classList.add('h-hidden');

                if (fullReset) {
                    currentTaskIndex = 0;
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        setTaskState(el, 'pending');
                    });
                    updateProgress(0);
                    fetch('install.php?step=7&reset=1', { method: 'POST' }).then(function() { runNextTask(); });
                } else {
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        if (el.dataset.status !== 'done') {
                            setTaskState(el, 'pending');
                        }
                    });
                    runNextTask();
                }
            }

            function runSeedBatch(el) {
                setTaskState(el, 'active');
                fetch('install.php?step=7&task=seed_batch', { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.seed_done === false) {
                            setTaskState(el, 'active', data.message);
                            runSeedBatch(el);
                        } else if (data.success) {
                            handleTaskSuccess(el);
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message);
                    });
            }

            function runMigrateBatch(el) {
                setTaskState(el, 'active');
                fetch('install.php?step=7&task=migrate_batch', { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.migrate_done === false) {
                            setTaskState(el, 'active', data.message);
                            runMigrateBatch(el);
                        } else if (data.success) {
                            handleTaskSuccess(el);
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message);
                    });
            }

            // Runs the optimize commands one at a time against
            // install-optimize.php, mirroring runSeedBatch/runMigrateBatch.
            // install-optimize.php is a standalone process with no session
            // access, so this function is what tracks the index across
            // requests. Once every command has succeeded, it confirms
            // completion with install.php so install_complete is only set
            // server-side after everything has actually run.
            function runOptimizeBatch(el, commands, index) {
                setTaskState(el, 'active');
                fetch('{$appFolder}/public/install-optimize.php?commands=' + encodeURIComponent(commands) + '&index=' + index + '&token=' + encodeURIComponent(optimizeToken))
                    .then(parseJsonResponse)
                    .then(function(data) {
                        if (!data.success) {
                            throw new Error(data.message);
                        }
                        if (data.done === false) {
                            setTaskState(el, 'active', data.message);
                            runOptimizeBatch(el, commands, index + 1);
                            return;
                        }
                        return fetch('install.php?step=7&task=optimize_confirm', { method: 'POST' }).then(parseJsonResponse);
                    })
                    .then(function(data) {
                        if (!data) {
                            return;
                        }
                        if (data.success) {
                            handleTaskSuccess(el);
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message);
                    });
            }

            function parseJsonResponse(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        throw new Error('Server returned HTTP ' + r.status + ': ' + text.substring(0, 500));
                    });
                }
                return r.json();
            }

            function handleTaskError(el, message) {
                setTaskState(el, 'error', message);
                document.getElementById('install-error').classList.remove('h-hidden');
                document.getElementById('error-message').textContent = 'Installation failed: ' + message;
                document.getElementById('install-actions').classList.remove('h-hidden');
                document.getElementById('retry-btn').classList.remove('h-hidden');
                document.getElementById('start-over-btn').classList.remove('h-hidden');
            }

            function handleTaskSuccess(el) {
                setTaskState(el, 'done');
                currentTaskIndex++;
                updateProgress((currentTaskIndex / tasks.length) * 100);
                runNextTask();
            }

            function runNextTask() {
                if (currentTaskIndex >= tasks.length) {
                    document.getElementById('install-actions').classList.remove('h-hidden');
                    document.getElementById('continue-btn').classList.remove('h-hidden');
                    return;
                }

                var task = tasks[currentTaskIndex];
                var el = document.querySelector('.task-item[data-task="' + task + '"]');
                setTaskState(el, 'active');

                if (cleanProcessTasks.hasOwnProperty(task)) {
                    fetch('install.php?step=7&task=' + task, { method: 'POST' })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) {
                                throw new Error(data.message);
                            }
                            runOptimizeBatch(el, cleanProcessTasks[task], 0);
                        })
                        .catch(function(err) {
                            handleTaskError(el, err.message);
                        });
                    return;
                }

                fetch('install.php?step=7&task=' + task, { method: 'POST' })
                    .then(parseJsonResponse)
                    .then(function(data) {
                        if (data.success && data.extract_done === false) {
                            setTaskState(el, 'active', data.message);
                            updateProgress(data.percent || 0);
                            runNextTask();
                        } else if (data.success && data.seed_done === false) {
                            setTaskState(el, 'active', data.message);
                            runSeedBatch(el);
                        } else if (data.success && data.migrate_done === false) {
                            setTaskState(el, 'active', data.message);
                            runMigrateBatch(el);
                        } else if (data.success) {
                            handleTaskSuccess(el);
                        } else {
                            handleTaskError(el, data.message);
                        }
                    })
                    .catch(function(err) {
                        handleTaskError(el, err.message);
                    });
            }

            document.getElementById('retry-btn').addEventListener('click', function() {
                runTasks(false);
            });

            if (currentTaskIndex < tasks.length) {
                runTasks(true);
            } else {
                document.getElementById('install-actions').classList.remove('h-hidden');
                document.getElementById('continue-btn').classList.remove('h-hidden');
            }
        </script>
HTML;

        $this->renderLayout('Installing', $content, 7);
    }

    private function renderCron(): void
    {
        $appPath = realpath(__DIR__.'/'.APP_FOLDER) ?: __DIR__.'/'.APP_FOLDER;
        $phpBinary = PHP_BINARY ?: '/usr/bin/php';
        $cronCommand = "* * * * * {$phpBinary} {$appPath}/artisan schedule:run >> /dev/null 2>&1";

        $content = <<<HTML
        <p class="h-lede">Your application requires a scheduled task (cron job) to run background processes such as sending emails, expiring memberships, and running health checks.</p>

        <!-- Cron Job Card -->
        <div class="h-card" style="padding:0; margin-bottom:24px;">
            <div class="h-card-header" style="margin:0; border-radius:22px 22px 0 0;">
                <span class="icon">⏰</span>
                <div>
                    <h3>Cron Job Setup</h3>
                    <p>Add this cron job to your server</p>
                </div>
            </div>
            <div style="padding:24px;">
                <div class="h-code-block accent">
                    <code id="cron-command">{$cronCommand}</code>
                </div>
                <button type="button" class="h-btn h-btn-ghost" id="copy-cron-btn">📋 Copy to Clipboard</button>
            </div>
        </div>
        <script>
            function initCopyButton(buttonId, sourceId) {
                var btn = document.getElementById(buttonId);
                var codeEl = document.getElementById(sourceId);
                var originalLabel = btn.textContent;

                function showCopied() {
                    btn.textContent = '✓ Copied!';
                    setTimeout(function() { btn.textContent = originalLabel; }, 2000);
                }

                function selectFallback() {
                    var range = document.createRange();
                    range.selectNodeContents(codeEl);
                    var selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                    btn.textContent = 'Press Ctrl+C / Cmd+C to copy';
                    setTimeout(function() { btn.textContent = originalLabel; }, 3000);
                }

                btn.addEventListener('click', function() {
                    if (!navigator.clipboard) {
                        selectFallback();
                        return;
                    }
                    navigator.clipboard.writeText(codeEl.textContent).then(showCopied).catch(selectFallback);
                });
            }

            initCopyButton('copy-cron-btn', 'cron-command');
        </script>

        <!-- How to Add Instructions -->
        <h3 class="h-section-title"><span class="bump"></span>How to Add a Cron Job</h3>
        <div style="margin-bottom:24px;">
            <details class="h-details">
                <summary>cPanel</summary>
                <ol>
                    <li>Log in to cPanel and find "Cron Jobs" under "Advanced"</li>
                    <li>Set the timing to "Once Per Minute" (or <code>* * * * *</code>)</li>
                    <li>Paste the command above into the "Command" field</li>
                    <li>Click "Add New Cron Job"</li>
                </ol>
            </details>
            <details class="h-details">
                <summary>Plesk</summary>
                <ol>
                    <li>Go to "Scheduled Tasks" in your Plesk panel</li>
                    <li>Click "Add Task"</li>
                    <li>Set it to run every minute</li>
                    <li>Paste the command above</li>
                </ol>
            </details>
            <details class="h-details">
                <summary>SSH / Terminal</summary>
                <ol>
                    <li>Connect to your server via SSH</li>
                    <li>Run <code>crontab -e</code></li>
                    <li>Add the command above as a new line</li>
                    <li>Save and exit</li>
                </ol>
            </details>
        </div>

        <!-- Actions -->
        <div class="h-actions end">
            <a href="install.php?step=9" class="h-btn h-btn-primary">Continue →</a>
        </div>
HTML;

        $this->renderLayout('Cron Job Setup', $content, 8);
    }

    private function renderComplete(): void
    {
        $appUrl = $_SESSION['installer']['settings']['app_url'] ?? '';
        $adminEmail = htmlspecialchars($_SESSION['installer']['admin']['email'] ?? '');

        $appDir = __DIR__.'/'.APP_FOLDER;
        $envPath = $appDir.'/.env';

        $appKey = $_SESSION['installer']['app_key'] ?? null;
        if ($appKey === null) {
            $envContent = @file_get_contents($envPath);
            if ($envContent && preg_match('/^APP_KEY=(.+)$/m', $envContent, $m)) {
                $appKey = trim($m[1]);
            }
        }
        $appKey = htmlspecialchars($appKey ?? '');

        $deletionMessages = '';
        $zipPath = __DIR__.'/'.ZIP_FILENAME;
        $installPath = __DIR__.'/install.php';

        $zipDeleted = false;
        $installDeleted = false;

        if (file_exists($zipPath)) {
            $zipDeleted = @unlink($zipPath);
        } else {
            $zipDeleted = true;
        }

        if (! $zipDeleted) {
            $deletionMessages .= '<div class="h-alert h-alert-warn">
                <div class="icon">
                    '.$this->statusIcon('warning', 16).'
                </div>
                <div>
                    <p class="t">Manual Cleanup Required</p>
                    <p class="d">Could not automatically delete <strong>'.ZIP_FILENAME.'</strong>. Please delete it manually for security.</p>
                </div>
            </div>';
        }

        $this->cleanupOptimizerEndpoint();

        $selfDeleteScript = __DIR__.'/_cleanup.php';
        $loginUrl = rtrim($appUrl, '/').'/login';
        file_put_contents($selfDeleteScript, '<?php @unlink(__DIR__ . "/install.php"); @unlink(__DIR__ . "/'.APP_FOLDER.'/public/install-optimize.php"); @unlink(__FILE__); header("Location: '.$loginUrl.'"); exit;');

        $cleanupUrl = htmlspecialchars(dirname($_SERVER['SCRIPT_NAME']).'/_cleanup.php');
        $cleanupUrl = str_replace('//', '/', $cleanupUrl);

        $appName = ucwords(str_replace(['-', '_'], ' ', APP_FOLDER));
        $bigCheckIcon = $this->statusIcon('check', 32);

        $content = <<<HTML
        <!-- Success Icon -->
        <div style="display:flex; justify-content:center;">
            <div class="h-success-icon h-bounce">
                {$bigCheckIcon}
            </div>
        </div>

        <!-- Success Message -->
        <div class="h-success-head">
            <h2>Installation Complete!</h2>
            <p>Your {$appName} application has been successfully installed.</p>
        </div>

        {$deletionMessages}

        <!-- Admin Credentials -->
        <div class="h-credentials">
            <h3 class="h-section-title" style="margin-bottom:6px;"><span class="bump"></span>Admin Login Details</h3>
            <div class="row">
                <span class="icon">🔗</span>
                <div>
                    <p class="t">Admin Login</p>
                    <a href="{$cleanupUrl}">{$appUrl}/login</a>
                </div>
            </div>
            <div class="row">
                <span class="icon">📧</span>
                <div>
                    <p class="t">Email</p>
                    <span class="v">{$adminEmail}</span>
                </div>
            </div>
            <div class="row">
                <span class="icon">🔒</span>
                <div>
                    <p class="t">Password</p>
                    <span class="v">(the password you entered during setup)</span>
                </div>
            </div>
        </div>

        <!-- Application Key -->
        <div class="h-card" style="padding:0; margin-bottom:20px;">
            <div class="h-card-header" style="margin:0; border-radius:22px 22px 0 0;">
                <span class="icon">🔑</span>
                <div>
                    <h3>Application Key</h3>
                    <p>Used to encrypt sessions, cookies, and other sensitive data</p>
                </div>
            </div>
            <div style="padding:24px;">
                <div class="h-code-block accent">
                    <code id="app-key-value">{$appKey}</code>
                </div>
                <button type="button" class="h-btn h-btn-ghost" id="copy-key-btn">📋 Copy to Clipboard</button>
            </div>
        </div>
        <script>
            function initCopyButton(buttonId, sourceId) {
                var btn = document.getElementById(buttonId);
                var codeEl = document.getElementById(sourceId);
                var originalLabel = btn.textContent;

                function showCopied() {
                    btn.textContent = '✓ Copied!';
                    setTimeout(function() { btn.textContent = originalLabel; }, 2000);
                }

                function selectFallback() {
                    var range = document.createRange();
                    range.selectNodeContents(codeEl);
                    var selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                    btn.textContent = 'Press Ctrl+C / Cmd+C to copy';
                    setTimeout(function() { btn.textContent = originalLabel; }, 3000);
                }

                btn.addEventListener('click', function() {
                    if (!navigator.clipboard) {
                        selectFallback();
                        return;
                    }
                    navigator.clipboard.writeText(codeEl.textContent).then(showCopied).catch(selectFallback);
                });
            }

            initCopyButton('copy-key-btn', 'app-key-value');
        </script>

        <!-- Application Key Warning -->
        <div class="h-alert h-alert-warn">
            <div class="icon">🔑</div>
            <div>
                <p class="t">Keep This Key Safe</p>
                <p class="d">This key encrypts sensitive application data, including sessions, cookies, and any encrypted fields. If it is ever lost or changed, that data becomes permanently unrecoverable — for example, every logged-in user would be instantly signed out and unable to log back in until they reset their password, and any data your application stores in an encrypted form (such as saved payment details or personal information) would turn into unreadable garbage forever. It is stored in the <code>.env</code> file at <code>{$envPath}</code> on this server — back it up securely (e.g. in a password manager) and never commit it to version control, email it, or share it insecurely.</p>
            </div>
        </div>

        <!-- Security Warning -->
        <div class="h-alert h-alert-warn">
            <div class="icon">🔒</div>
            <div>
                <p class="t">Important</p>
                <p class="d">For security, the installer files will be deleted when you proceed. If auto-deletion fails, please manually delete <code>install.php</code> and <code>{$zipPath}</code> from your server.</p>
            </div>
        </div>

        <!-- Actions -->
        <div class="h-actions end">
            <a href="{$cleanupUrl}" class="h-btn h-btn-good">
                <span>🚀</span> Go to Application →
            </a>
        </div>
HTML;

        session_destroy();

        $this->renderLayout('Installation Complete', $content, 9);
    }

    private function renderAlreadyInstalled(): void
    {
        $warningIcon = $this->statusIcon('warning', 20);

        $content = <<<HTML
        <!-- Warning Alert -->
        <div class="h-alert h-alert-warn" style="align-items:flex-start;">
            <div class="icon" style="width:44px; height:44px; border-radius:14px;">
                {$warningIcon}
            </div>
            <div>
                <p class="t" style="font-size:1rem; margin-bottom:6px;">Already Installed</p>
                <p class="d">This application appears to already be installed. For security reasons, the installer cannot be run again.</p>
                <p class="d" style="margin-top:6px;">Please delete <code>install.php</code> from your server immediately.</p>
            </div>
        </div>
HTML;

        $this->renderLayout('Already Installed', $content, 0);
    }

    // ========================================================================
    // HTML Helpers
    // ========================================================================

}
