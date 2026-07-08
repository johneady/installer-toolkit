<?php

trait RendersSteps
{
    // ========================================================================
    // Step Renderers
    // ========================================================================

    private function renderStep(int $step): void
    {
        switch ($step) {
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

    private function renderEula(): void
    {
        $errors = $this->renderErrors();
        $eula = htmlspecialchars(EULA_TEXT);

        $content = <<<HTML
        {$errors}
        <form method="POST" action="install.php?step=1">
            <!-- EULA Box -->
            <div class="bg-gradient-to-br from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6 shadow-sm">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⚖️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white text-base">End User License Agreement</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Please read carefully before proceeding</p>
                        </div>
                    </div>
                </div>
                <div class="p-6 max-h-80 overflow-y-auto scrollbar-hide">
                    <div class="prose prose-sm max-w-none text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">{$eula}</div>
                </div>
            </div>

            <!-- Checkbox -->
            <div class="bg-gradient-to-r from-sky-50 to-cyan-50 dark:from-sky-900/20 dark:to-cyan-900/20 rounded-xl p-4 mb-6 border border-sky-200 dark:border-sky-800">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="accept_eula" value="1" id="accept-eula" class="w-5 h-5 mt-0.5 rounded border-slate-300 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                    <div>
                        <span class="font-medium text-slate-900 dark:text-white">I have read and agree to the End User License Agreement</span>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">By checking this box, you confirm that you understand and accept all terms</p>
                    </div>
                </label>
            </div>

            <!-- Actions -->
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" id="accept-btn" disabled>I Accept →</button>
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
            $items .= "<div class=\"flex items-center gap-4 p-4 rounded-xl border-2 bg-white dark:bg-slate-800 border-emerald-300 dark:border-emerald-700 shadow-sm\">
                <div class=\"flex-shrink-0 w-10 h-10 rounded-xl text-emerald-500 bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center\">
                    <span class=\"text-lg font-bold\">✓</span>
                </div>
                <div class=\"flex-1\">
                    <h4 class=\"font-semibold text-slate-900 dark:text-white text-sm\">".count($passed)." requirement".(count($passed) === 1 ? '' : 's')." met</h4>
                    <p class=\"text-xs text-slate-600 dark:text-slate-400 mt-0.5\">".implode(', ', array_map(fn ($r) => $r['name'], $passed))."</p>
                </div>
                <span class=\"px-3 py-1 rounded-full text-emerald-500 bg-emerald-100 dark:bg-emerald-900/30 text-xs font-semibold\">Passed</span>
            </div>";
        }
        foreach ($notPassed as $r) {
            $icon = $r['critical'] ? '✕' : '⚠';
            $statusClass = $r['critical'] ? 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-300 dark:border-yellow-700';
            $iconClass = $r['critical'] ? 'text-red-500 bg-red-100 dark:bg-red-900/30' : 'text-yellow-500 bg-yellow-100 dark:bg-yellow-900/30';
            $items .= "<div class=\"flex items-center gap-4 p-4 rounded-xl border-2 {$statusClass} shadow-sm hover:shadow-md transition-shadow\">
                <div class=\"flex-shrink-0 w-10 h-10 rounded-xl {$iconClass} flex items-center justify-center\">
                    <span class=\"text-lg font-bold\">{$icon}</span>
                </div>
                <div class=\"flex-1\">
                    <h4 class=\"font-semibold text-slate-900 dark:text-white text-sm\">{$r['name']}</h4>
                    <p class=\"text-xs text-slate-600 dark:text-slate-400 mt-0.5\">{$r['detail']}</p>
                </div>
                <span class=\"px-3 py-1 rounded-full {$iconClass} text-xs font-semibold\">" . ($r['critical'] ? 'Critical' : 'Warning') . "</span>
            </div>";
        }

        $disabled = $allCriticalPassed ? '' : 'disabled';
        $retestButton = $allCriticalPassed ? '' : '<button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all" onclick="window.location.href=\'install.php?step=2\'">Re-Test</button>';
        $warning = $allCriticalPassed ? '' : '<div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <span class="text-red-500 text-lg">⚠️</span>
            </div>
            <div>
                <h3 class="font-semibold text-red-900 dark:text-red-400">Requirements Not Met</h3>
                <p class="text-red-700 dark:text-red-300 text-sm">Some critical requirements are not met. Please resolve them before continuing.</p>
            </div>
        </div>';

        $content = <<<HTML
        {$warning}
        <form method="POST" action="install.php?step=2">
        <div class="grid gap-3 mb-6">{$items}</div>
        <div class="flex justify-between items-center gap-3">
            <a href="install.php?step=1" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
            <div class="flex gap-3">
                {$retestButton}
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" {$disabled}>Continue →</button>
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
        <div id="db-test-result" class="hidden"></div>
        <form method="POST" action="install.php?step=3" id="db-form">
            <!-- Database Configuration Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">🗄️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">MySQL Database Connection</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Provide your database credentials</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Host <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_host" id="db_host" value="{$host}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🌐</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Port</label>
                            <div class="relative">
                                <input type="text" name="db_port" id="db_port" value="{$port}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔢</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Name <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_name" id="db_name" value="{$name}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">📊</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Username <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="db_user" id="db_user" value="{$user}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Database Password</label>
                            <div class="relative">
                                <input type="password" name="db_pass" id="db_pass" value="{$pass}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=2" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <div class="flex gap-3">
                    <button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all flex items-center gap-2" id="test-db-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('test-db-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('db-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin">⏳</span> Testing...';
                resultDiv.classList.add('hidden');

                var formData = new FormData(document.getElementById('db-form'));

                fetch('install.php?step=3&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = data.success 
                        ? 'bg-gradient-to-r from-emerald-50 to-green-50 dark:from-emerald-900/20 dark:to-green-900/20 border-2 border-emerald-300 dark:border-emerald-700 rounded-xl p-4 mb-6 flex items-start gap-3'
                        : 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg ' + (data.success ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-red-100 dark:bg-red-900/30') + ' flex items-center justify-center">' +
                        '<span class="' + (data.success ? 'text-emerald-500' : 'text-red-500') + ' text-lg">' + (data.success ? '✓' : '✕') + '</span></div>' +
                        '<div><p class="font-semibold ' + (data.success ? 'text-emerald-900 dark:text-emerald-400' : 'text-red-900 dark:text-red-400') + '">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="text-sm ' + (data.success ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300') + '">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center"><span class="text-red-500 text-lg">✕</span></div><div><p class="font-semibold text-red-900 dark:text-red-400">Error</p><p class="text-sm text-red-700 dark:text-red-300">An error occurred while testing the connection.</p></div>';
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
            <div class="mb-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
                    Application
                </h3>
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Application Name <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" name="app_name" id="app_name" value="{$appName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🏷️</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Application URL <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="url" name="app_url" id="app_url" value="{$appUrl}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🌐</span>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Timezone <span class="text-red-500">*</span></label>
                        <select name="timezone" id="timezone" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white cursor-pointer">{$tzOptions}</select>
                    </div>
                </div>
            </div>

            <!-- Initial Data -->
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2 flex items-center gap-2">
                    <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
                    Initial Data
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-sm mb-4">Choose how much content to pre-populate your site with. You can always add your own data later.</p>
                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:border-sky-300 dark:hover:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all bg-white dark:bg-slate-800">
                        <input type="radio" name="sample_data" value="essential"{$essentialChecked} class="w-5 h-5 mt-0.5 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                        <div>
                            <span class="font-semibold text-slate-900 dark:text-white">Essentials only</span>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Sets up core configuration, menus, and pages — a clean slate ready for your own affiliates and content.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:border-sky-300 dark:hover:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all bg-white dark:bg-slate-800">
                        <input type="radio" name="sample_data" value="full"{$fullChecked} class="w-5 h-5 mt-0.5 text-sky-500 focus:ring-sky-500 focus:ring-offset-0 transition-all">
                        <div>
                            <span class="font-semibold text-slate-900 dark:text-white">Full demonstration data</span>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Includes sample affiliates, applications, blog posts, and more — ideal for exploring all features before going live.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-200 dark:border-slate-700">
                <a href="install.php?step=3" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
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
        $smtpDisplay = $mailMailer === 'smtp' ? '' : 'hidden';
        $sendmailDisplay = $mailMailer === 'sendmail' ? '' : 'hidden';
        $fromDisplay = $mailMailer === 'log' ? 'hidden' : '';

        $content = <<<HTML
        {$errors}
        <div id="mail-test-result" class="hidden"></div>
        <form method="POST" action="install.php?step=5" id="mail-form">
            <!-- Mail Configuration -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">✉️</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Email Configuration</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Set up how this application sends email notifications</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Mail Driver</label>
                        <select name="mail_mailer" id="mail_mailer" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white cursor-pointer">
                            <option value="smtp"{$smtpSelected}>SMTP</option>
                            <option value="sendmail"{$sendmailSelected}>Sendmail (PHP mail)</option>
                            <option value="log"{$logSelected}>Log (no emails sent)</option>
                        </select>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Select "Log" if you want to configure email later.</p>
                    </div>

                    <!-- SMTP Fields -->
                    <div id="smtp-fields" class="{$smtpDisplay}">
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Host</label>
                                <input type="text" name="mail_host" id="mail_host" value="{$mailHost}" placeholder="smtp.example.com" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Port</label>
                                <input type="text" name="mail_port" id="mail_port" value="{$mailPort}" placeholder="587" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Username</label>
                                <input type="text" name="mail_username" id="mail_username" value="{$mailUsername}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">SMTP Password</label>
                                <input type="password" name="mail_password" id="mail_password" value="{$mailPassword}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                            </div>
                        </div>
                    </div>

                    <!-- Sendmail Info -->
                    <div id="sendmail-fields" class="{$sendmailDisplay}">
                        <div class="bg-sky-50 dark:bg-sky-900/20 rounded-xl p-4 border border-sky-200 dark:border-sky-800">
                            <p class="text-sm text-sky-800 dark:text-sky-300">Uses your server's built-in sendmail/PHP mail function. No additional server configuration needed.</p>
                        </div>
                    </div>

                    <!-- From Fields -->
                    <div id="from-fields" class="{$fromDisplay} mt-6 grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">From Address</label>
                            <input type="email" name="mail_from_address" id="mail_from_address" value="{$mailFromAddress}" placeholder="noreply@yourdomain.com" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">From Name</label>
                            <input type="text" name="mail_from_name" id="mail_from_name" value="{$mailFromName}" placeholder="{$appName}" class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=4" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <div class="flex gap-3">
                    <button type="button" class="px-6 py-3 rounded-xl bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-400 font-semibold border-2 border-sky-300 dark:border-sky-700 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all flex items-center gap-2" id="test-mail-btn">
                        <span>🔗</span> Test Connection
                    </button>
                    <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
                </div>
            </div>
        </form>
        <script>
            document.getElementById('mail_mailer').addEventListener('change', function() {
                document.getElementById('smtp-fields').classList.toggle('hidden', this.value !== 'smtp');
                document.getElementById('sendmail-fields').classList.toggle('hidden', this.value !== 'sendmail');
                document.getElementById('from-fields').classList.toggle('hidden', this.value === 'log');
                document.getElementById('mail-test-result').classList.add('hidden');
            });

            document.getElementById('test-mail-btn').addEventListener('click', function() {
                var btn = this;
                var resultDiv = document.getElementById('mail-test-result');
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin">⏳</span> Testing...';
                resultDiv.classList.add('hidden');

                var formData = new FormData(document.getElementById('mail-form'));

                fetch('install.php?step=5&action=test', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = data.success 
                        ? 'bg-gradient-to-r from-emerald-50 to-green-50 dark:from-emerald-900/20 dark:to-green-900/20 border-2 border-emerald-300 dark:border-emerald-700 rounded-xl p-4 mb-6 flex items-start gap-3'
                        : 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg ' + (data.success ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-red-100 dark:bg-red-900/30') + ' flex items-center justify-center">' +
                        '<span class="' + (data.success ? 'text-emerald-500' : 'text-red-500') + ' text-lg">' + (data.success ? '✓' : '✕') + '</span></div>' +
                        '<div><p class="font-semibold ' + (data.success ? 'text-emerald-900 dark:text-emerald-400' : 'text-red-900 dark:text-red-400') + '">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="text-sm ' + (data.success ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300') + '">' + data.message + '</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span>🔗</span> Test Connection';
                })
                .catch(function(err) {
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3';
                    resultDiv.innerHTML = '<div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center"><span class="text-red-500 text-lg">✕</span></div><div><p class="font-semibold text-red-900 dark:text-red-400">Error</p><p class="text-sm text-red-700 dark:text-red-300">An error occurred while testing the mail connection.</p></div>';
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
        <p class="text-slate-600 dark:text-slate-400 mb-6">Create your administrator account. You will use these credentials to log into the admin panel.</p>
        <form method="POST" action="install.php?step=6">
            <!-- Admin Account Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">👤</span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Administrator Account</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Set up your admin credentials</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-5">
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">First Name <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="text" name="admin_first_name" id="admin_first_name" value="{$firstName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Last Name <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="text" name="admin_last_name" id="admin_last_name" value="{$lastName}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">👤</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Email Address <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="email" name="admin_email" id="admin_email" value="{$email}" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">📧</span>
                            </div>
                        </div>
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="admin_password" id="admin_password" minlength="8" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                                </div>
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Minimum 8 characters</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Confirm Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="admin_password_confirm" id="admin_password_confirm" minlength="8" required class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">🔒</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between items-center gap-3">
                <a href="install.php?step=5" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all">← Back</a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</button>
            </div>
        </form>
HTML;

        $this->renderLayout('Admin Account', $content, 6);
    }

    private function renderInstall(): void
    {
        $tasks = [
            'extract' => 'Extracting application files',
            'htaccess' => 'Configuring web server',
            'env' => 'Generating environment configuration',
            'migrate' => 'Running database migrations',
            'seed' => 'Seeding database with initial data',
            'storage_link' => 'Creating storage symlink',
            'config_clear' => 'Clearing configuration cache',
            'package_discover' => 'Discovering packages',
            'config_cache' => 'Caching configuration',
            'event_cache' => 'Caching events',
            'route_cache' => 'Caching routes',
            'view_cache' => 'Caching views',
            'icons_cache' => 'Caching icons',
            'filament_optimize' => 'Optimizing Filament',
        ];

        $completedTasks = $_SESSION['installer']['completed_tasks'] ?? [];

        $taskList = '';
        foreach ($tasks as $key => $label) {
            $status = in_array($key, $completedTasks) ? 'done' : 'pending';
            $taskList .= "<div class=\"task-item flex items-center gap-4 p-4 rounded-xl border border-slate-200 dark:border-slate-700 transition-all bg-white dark:bg-slate-800\" data-task=\"{$key}\" data-status=\"{$status}\">";
            $taskList .= '<span class="task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500"></span>';
            $taskList .= "<span class=\"task-label flex-1 text-sm font-medium text-slate-900 dark:text-white\">{$label}</span>";
            $taskList .= '<span class="task-message text-xs text-red-600 dark:text-red-400 text-right font-medium max-w-[50%]"></span>';
            $taskList .= '</div>';
        }

        $tasksJson = json_encode(array_keys($tasks));

        if (! isset($_SESSION['installer']['optimize_token'])) {
            $_SESSION['installer']['optimize_token'] = bin2hex(random_bytes(32));
        }

        $optimizeToken = $_SESSION['installer']['optimize_token'];

        $cleanProcessTasks = json_encode([
            'config_clear' => 'config:clear',
            'package_discover' => 'package:discover',
            'config_cache' => 'config:cache',
            'event_cache' => 'event:cache',
            'route_cache' => 'route:cache',
            'view_cache' => 'view:cache',
            'icons_cache' => 'icons:cache',
            'filament_optimize' => 'filament:optimize',
        ]);

        $appFolder = APP_FOLDER;

        $content = <<<HTML
        <p class="text-slate-600 dark:text-slate-400 mb-6">Installing your application. Please do not close this page.</p>
        
        <!-- Progress Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-sky-500 to-cyan-500 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center">
                            <span class="text-white text-xl">⚡</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">Installation Progress</h3>
                            <p class="text-sky-100 text-sm">Step 7 of 9</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-white" id="progress-percent">0%</div>
                        <div class="text-sky-100 text-sm">Complete</div>
                    </div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="h-2 bg-slate-100 dark:bg-slate-700">
                <div id="progress-bar" class="h-full bg-gradient-to-r from-sky-500 to-cyan-500 transition-all duration-500" style="width: 0%"></div>
            </div>

            <!-- Task List -->
            <div class="p-6 space-y-3" id="task-list">{$taskList}</div>
        </div>

        <div id="install-error" class="hidden bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <span class="text-red-500 text-lg">✕</span>
            </div>
            <div>
                <h3 class="font-semibold text-red-900 dark:text-red-400">Installation Failed</h3>
                <p class="text-sm text-red-700 dark:text-red-300" id="error-message"></p>
            </div>
        </div>

        <div class="actions hidden flex justify-between items-center gap-3" id="install-actions">
            <button type="button" class="px-6 py-3 rounded-xl bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-600 transition-all hidden" id="retry-btn">Retry</button>
            <a href="install.php?step=8" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all hidden" id="continue-btn">Continue →</a>
        </div>
        <script>
            var tasks = {$tasksJson};
            var cleanProcessTasks = {$cleanProcessTasks};
            var optimizeToken = '{$optimizeToken}';
            var currentTaskIndex = 0;

            document.querySelectorAll('.task-item[data-status="done"]').forEach(function(el) {
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✓';
                icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                currentTaskIndex++;
            });

            function updateProgress(percent) {
                document.getElementById('progress-percent').textContent = Math.round(percent) + '%';
                document.getElementById('progress-bar').style.width = percent + '%';
            }

            function runTasks(fullReset) {
                document.getElementById('install-error').classList.add('hidden');
                document.getElementById('retry-btn').classList.add('hidden');

                if (fullReset) {
                    currentTaskIndex = 0;
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        var icon = el.querySelector('.task-icon');
                        el.classList.remove('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400', 'bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400', 'bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                        icon.innerHTML = '';
                        icon.className = 'task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500';
                        el.querySelector('.task-label').classList.remove('text-emerald-600', 'dark:text-emerald-400', 'text-red-600', 'dark:text-red-400', 'text-sky-600', 'dark:text-sky-400');
                        el.querySelector('.task-message').textContent = '';
                    });
                    updateProgress(0);
                    fetch('install.php?step=7&reset=1', { method: 'POST' }).then(function() { runNextTask(); });
                } else {
                    document.querySelectorAll('.task-item').forEach(function(el) {
                        var icon = el.querySelector('.task-icon');
                        if (!icon.innerHTML.includes('✓')) {
                            el.classList.remove('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400', 'bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                            icon.className = 'task-icon flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500';
                            icon.innerHTML = '';
                            el.querySelector('.task-label').classList.remove('text-red-600', 'dark:text-red-400', 'text-sky-600', 'dark:text-sky-400');
                            el.querySelector('.task-message').textContent = '';
                        }
                    });
                    runNextTask();
                }
            }

            function runSeedBatch(el) {
                el.classList.add('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '<svg class="w-5 h-5 text-sky-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                icon.classList.add('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-sky-600', 'dark:text-sky-400');
                fetch('install.php?step=7&task=seed_batch', { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.seed_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            runSeedBatch(el);
                        } else if (data.success) {
                            el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                            icon.innerHTML = '✓';
                            icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                            icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                            el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                            el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                            el.querySelector('.task-message').textContent = '';
                            currentTaskIndex++;
                            updateProgress((currentTaskIndex / tasks.length) * 100);
                            runNextTask();
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(function(err) {
                        el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                        icon.innerHTML = '✕';
                        icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                        icon.classList.add('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400');
                        el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                        el.querySelector('.task-label').classList.add('text-red-600', 'dark:text-red-400');
                        document.getElementById('install-error').classList.remove('hidden');
                        document.getElementById('error-message').textContent = 'Installation failed: ' + err.message;
                        document.getElementById('install-actions').classList.remove('hidden');
                        document.getElementById('install-actions').classList.add('flex');
                        document.getElementById('retry-btn').classList.remove('hidden');
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
                el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✕';
                icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                icon.classList.add('bg-red-100', 'dark:bg-red-900/30', 'text-red-500', 'dark:text-red-400');
                el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-red-600', 'dark:text-red-400');
                el.querySelector('.task-message').textContent = message || '';
                document.getElementById('install-error').classList.remove('hidden');
                document.getElementById('error-message').textContent = 'Installation failed: ' + message;
                document.getElementById('install-actions').classList.remove('hidden');
                document.getElementById('install-actions').classList.add('flex');
                document.getElementById('retry-btn').classList.remove('hidden');
            }

            function handleTaskSuccess(el) {
                el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700', 'text-sky-600', 'dark:text-sky-400');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '✓';
                icon.classList.remove('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                icon.classList.add('bg-emerald-100', 'dark:bg-emerald-900/30', 'text-emerald-500', 'dark:text-emerald-400');
                el.querySelector('.task-label').classList.remove('text-sky-600', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-emerald-600', 'dark:text-emerald-400');
                el.querySelector('.task-message').textContent = '';
                currentTaskIndex++;
                updateProgress((currentTaskIndex / tasks.length) * 100);
                runNextTask();
            }

            function runNextTask() {
                if (currentTaskIndex >= tasks.length) {
                    document.getElementById('install-actions').classList.remove('hidden');
                    document.getElementById('install-actions').classList.add('flex');
                    document.getElementById('continue-btn').classList.remove('hidden');
                    return;
                }

                var task = tasks[currentTaskIndex];
                var el = document.querySelector('.task-item[data-task="' + task + '"]');
                el.classList.add('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                var icon = el.querySelector('.task-icon');
                icon.innerHTML = '<svg class="w-5 h-5 text-sky-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                icon.classList.add('bg-sky-100', 'dark:bg-sky-900/30', 'text-sky-500', 'dark:text-sky-400');
                el.querySelector('.task-label').classList.add('text-sky-600', 'dark:text-sky-400');

                if (cleanProcessTasks.hasOwnProperty(task)) {
                    fetch('install.php?step=7&task=' + task, { method: 'POST' })
                        .then(parseJsonResponse)
                        .then(function(data) {
                            if (!data.success) {
                                throw new Error(data.message);
                            }
                            var command = cleanProcessTasks[task];
                            return fetch('{$appFolder}/public/install-optimize.php?command=' + encodeURIComponent(command) + '&token=' + encodeURIComponent(optimizeToken)).then(parseJsonResponse);
                        })
                        .then(function(data) {
                            if (data.success) {
                                handleTaskSuccess(el);
                            } else {
                                handleTaskError(el, data.message);
                            }
                        })
                        .catch(function(err) {
                            handleTaskError(el, err.message);
                        });
                    return;
                }

                fetch('install.php?step=7&task=' + task, { method: 'POST' })
                    .then(parseJsonResponse)
                    .then(function(data) {
                        el.classList.remove('bg-sky-50', 'dark:bg-sky-900/20', 'border-sky-300', 'dark:border-sky-700');
                        if (data.success && data.extract_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            updateProgress(data.percent || 0);
                            runNextTask();
                        } else if (data.success && data.seed_done === false) {
                            el.querySelector('.task-message').textContent = data.message || '';
                            runSeedBatch(el);
                            return;
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

            if (currentTaskIndex < tasks.length) {
                runTasks(true);
            } else {
                document.getElementById('install-actions').classList.remove('hidden');
                document.getElementById('install-actions').classList.add('flex');
                document.getElementById('continue-btn').classList.remove('hidden');
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
        <p class="text-slate-600 dark:text-slate-400 mb-6">Your application requires a scheduled task (cron job) to run background processes such as sending emails, expiring memberships, and running health checks.</p>

        <!-- Cron Job Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-slate-50 to-sky-50 dark:from-slate-800 dark:to-slate-900 px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">⏰</span>
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">Cron Job Setup</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Add this cron job to your server</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <div class="bg-slate-900 dark:bg-black rounded-xl p-4 mb-4 border border-slate-700">
                    <code class="text-emerald-400 text-sm font-mono break-all">{$cronCommand}</code>
                </div>
                <button type="button" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium border border-slate-300 dark:border-slate-600 hover:bg-slate-200 dark:hover:bg-slate-600 transition-all text-sm" onclick="navigator.clipboard.writeText(document.querySelector('.bg-slate-900 code').textContent).then(function(){this.textContent='Copied!';}.bind(this))">📋 Copy to Clipboard</button>
            </div>
        </div>

        <!-- How to Add Instructions -->
        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="w-1 h-6 bg-gradient-to-b from-sky-500 to-cyan-500 rounded-full"></span>
            How to Add a Cron Job
        </h3>
        <div class="space-y-3 mb-6">
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    cPanel
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Log in to cPanel and find "Cron Jobs" under "Advanced"</li>
                    <li>Set the timing to "Once Per Minute" (or <code class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-900 dark:text-white font-mono text-xs">* * * * *</code>)</li>
                    <li>Paste the command above into the "Command" field</li>
                    <li>Click "Add New Cron Job"</li>
                </ol>
            </details>
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    Plesk
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Go to "Scheduled Tasks" in your Plesk panel</li>
                    <li>Click "Add Task"</li>
                    <li>Set it to run every minute</li>
                    <li>Paste the command above</li>
                </ol>
            </details>
            <details class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <summary class="px-6 py-4 font-semibold text-slate-900 dark:text-white cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors flex items-center justify-between">
                    SSH / Terminal
                    <span class="text-slate-400 dark:text-slate-500 text-2xl transform transition-transform">+</span>
                </summary>
                <ol class="px-6 py-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    <li>Connect to your server via SSH</li>
                    <li>Run <code class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-900 dark:text-white font-mono text-xs">crontab -e</code></li>
                    <li>Add the command above as a new line</li>
                    <li>Save and exit</li>
                </ol>
            </details>
        </div>

        <!-- Actions -->
        <div class="flex justify-end">
            <a href="install.php?step=9" class="px-8 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-cyan-500 text-white font-semibold shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 transform hover:-translate-y-0.5 transition-all">Continue →</a>
        </div>
HTML;

        $this->renderLayout('Cron Job Setup', $content, 8);
    }

    private function renderComplete(): void
    {
        $appUrl = $_SESSION['installer']['settings']['app_url'] ?? '';
        $adminEmail = htmlspecialchars($_SESSION['installer']['admin']['email'] ?? '');

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
            $deletionMessages .= '<div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-4 mb-6 flex items-start gap-3">
                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                    <span class="text-yellow-500 text-lg">⚠️</span>
                </div>
                <div>
                    <h3 class="font-semibold text-yellow-900 dark:text-yellow-400">Manual Cleanup Required</h3>
                    <p class="text-sm text-yellow-700 dark:text-yellow-300">Could not automatically delete <strong>'.ZIP_FILENAME.'</strong>. Please delete it manually for security.</p>
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

        $content = <<<HTML
        <!-- Success Icon -->
        <div class="flex justify-center mb-6">
            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-500 to-green-500 flex items-center justify-center shadow-2xl shadow-emerald-500/30 animate-bounce">
                <span class="text-4xl">✓</span>
            </div>
        </div>

        <!-- Success Message -->
        <div class="text-center mb-8">
            <h2 class="text-2xl md:text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 mb-2">Installation Complete!</h2>
            <p class="text-slate-600 dark:text-slate-400">Your {$appName} application has been successfully installed.</p>
        </div>

        {$deletionMessages}

        <!-- Admin Credentials -->
        <div class="bg-gradient-to-r from-blue-50 to-sky-50 dark:from-blue-900/20 dark:to-sky-900/20 rounded-2xl p-6 mb-6 border-2 border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-bold text-blue-900 dark:text-blue-400 mb-4 flex items-center gap-2">
                <span class="w-1 h-6 bg-gradient-to-b from-blue-500 to-sky-500 rounded-full"></span>
                Admin Login Details
            </h3>
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">🔗</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Admin Login</p>
                        <a href="{$cleanupUrl}" class="text-blue-600 dark:text-blue-300 font-medium hover:underline">{$appUrl}/login</a>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">📧</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Email</p>
                        <p class="text-slate-700 dark:text-slate-300">{$adminEmail}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-500 text-xl">🔒</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-400">Password</p>
                        <p class="text-slate-700 dark:text-slate-300">(the password you entered during setup)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Warning -->
        <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-4 mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <span class="text-yellow-500 text-lg">🔒</span>
            </div>
            <div>
                <h3 class="font-semibold text-yellow-900 dark:text-yellow-400">Important</h3>
                <p class="text-sm text-yellow-700 dark:text-yellow-300">For security, the installer files will be deleted when you proceed. If auto-deletion fails, please manually delete <code class="px-2 py-1 rounded bg-yellow-100 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono text-xs">install.php</code> and <code class="px-2 py-1 rounded bg-yellow-100 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono text-xs">{$zipPath}</code> from your server.</p>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end">
            <a href="{$cleanupUrl}" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-green-500 text-white font-semibold shadow-lg shadow-emerald-500/30 hover:shadow-xl hover:shadow-emerald-500/40 transform hover:-translate-y-0.5 transition-all flex items-center gap-2">
                <span>🚀</span> Go to Application →
            </a>
        </div>
HTML;

        session_destroy();

        $this->renderLayout('Installation Complete', $content, 9);
    }

    private function renderAlreadyInstalled(): void
    {
        $content = <<<'HTML'
        <!-- Warning Alert -->
        <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-6 flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <span class="text-yellow-500 text-2xl">⚠️</span>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-bold text-yellow-900 dark:text-yellow-400 mb-2">Already Installed</h3>
                <p class="text-yellow-800 dark:text-yellow-300 mb-2">This application appears to already be installed. For security reasons, the installer cannot be run again.</p>
                <p class="text-sm text-yellow-700 dark:text-yellow-400">Please delete <code class="px-2 py-1 rounded bg-yellow-200 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-300 font-mono">install.php</code> from your server immediately.</p>
            </div>
        </div>
HTML;

        $this->renderLayout('Already Installed', $content, 0);
    }

    // ========================================================================
    // HTML Helpers
    // ========================================================================

}
