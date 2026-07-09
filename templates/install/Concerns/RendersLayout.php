<?php

trait RendersLayout
{
    /**
     * Inline SVG status icons (check / x / warning triangle) used in place
     * of emoji, which render inconsistently across OS/browser emoji fonts.
     * All use currentColor so they inherit whatever text-color class wraps
     * them. Kept here (rather than per-trait) since every step includes
     * this trait and needs the same three icons.
     */
    private function statusIcon(string $type, string $sizeClass = 'w-5 h-5'): string
    {
        return match ($type) {
            'check' => "<svg class=\"{$sizeClass} inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"3\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M4.5 12.75l6 6 9-13.5\" /></svg>",
            'x' => "<svg class=\"{$sizeClass} inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"3\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M6 18L18 6M6 6l12 12\" /></svg>",
            'warning' => "<svg class=\"{$sizeClass} inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z\" /></svg>",
            default => '',
        };
    }

    private function renderErrors(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        $html = '<div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-4 mb-6"><ul class="space-y-2">';
        foreach ($this->errors as $error) {
            $html .= '<li class="flex items-start gap-2 text-red-800 dark:text-red-300"><span class="text-red-500 mt-0.5">•</span>'.htmlspecialchars($error).'</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /**
     * Per-step icon and description. The "Step X of N" label is derived
     * separately in getStepLabel() from $stepNames/$settingsSubSteps so
     * it can never drift out of sync with the visual stepper.
     */
    private function getSidebarInfo(int $step): array
    {
        return match ($step) {
            1 => [
                'icon' => '📄',
                'desc' => 'Please read and accept the End User License Agreement before proceeding with the installation.',
            ],
            2 => [
                'icon' => '🔍',
                'desc' => 'Verifying that your server meets all the requirements needed to run successfully.',
            ],
            3 => [
                'icon' => '🗄️',
                'desc' => 'Enter the credentials for the database that you will use to store data.',
            ],
            4 => [
                'icon' => '⚙️',
                'desc' => 'Configure your application name, URL, timezone, and the amount of initial data to load.',
            ],
            5 => [
                'icon' => '✉️',
                'desc' => 'Set up how this application sends email notifications. You can always update this later in the admin panel.',
            ],
            6 => [
                'icon' => '👤',
                'desc' => 'Create the administrator account you will use to manage your website.',
            ],
            7 => [
                'icon' => '🚀',
                'desc' => 'Sit tight — The application is being installed on your server. Do not close this page.',
            ],
            8 => [
                'icon' => '⏰',
                'desc' => 'Set up a scheduled task so you can run background jobs automatically.',
            ],
            9 => [
                'icon' => '🎉',
                'desc' => 'Installation is complete. Your application is ready to go!',
            ],
            default => ['icon' => '⚙️', 'desc' => ''],
        };
    }

    /**
     * Computes "Step X of N" (optionally suffixed with the current
     * settings sub-step name) directly from $stepNames/$settingsSubSteps
     * — the same data renderStepIndicator() uses — so the two displays
     * can't disagree.
     */
    private function getStepLabel(int $step): string
    {
        if ($step === 0) {
            return '';
        }

        $total = count($this->stepNames);
        $visualNum = 0;
        $matchedNum = null;

        foreach ($this->stepNames as $num => $name) {
            $visualNum++;
            if ($num === $step || ($num === 3 && in_array($step, $this->settingsSubSteps))) {
                $matchedNum = $visualNum;
                break;
            }
        }

        if ($matchedNum === null) {
            return '';
        }

        $label = "Step {$matchedNum} of {$total}";

        if (in_array($step, $this->settingsSubSteps)) {
            $label .= ' — '.$this->stepNames[3];
        }

        return $label;
    }

    private function renderLayout(string $title, string $content, int $currentStep): void
    {
        $stepIndicator = $this->renderStepIndicator($currentStep);
        $subStepIndicator = $this->renderSubStepIndicator($currentStep);
        $installTimer = $this->renderInstallTimer($currentStep);
        $version = INSTALLER_VERSION;
        $sidebar = $this->getSidebarInfo($currentStep);
        $sidebarIcon = $sidebar['icon'];
        $sidebarDesc = htmlspecialchars($sidebar['desc']);
        $sidebarLabel = htmlspecialchars($this->getStepLabel($currentStep));

        // Exposed globally so per-step inline scripts (DB/mail connection
        // tests, task list, etc.) can build status HTML without duplicating
        // the SVG markup — same source as statusIcon() above. 'small' variants
        // are pre-sized here rather than shrunk client-side, so callers never
        // depend on the exact class string statusIcon() happens to emit.
        $iconsJson = json_encode([
            'check' => $this->statusIcon('check'),
            'x' => $this->statusIcon('x'),
            'warning' => $this->statusIcon('warning'),
            'checkSmall' => $this->statusIcon('check', 'w-3.5 h-3.5'),
            'xSmall' => $this->statusIcon('x', 'w-3.5 h-3.5'),
        ]);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Installer - {$title}</title>
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-25%); }
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        /* [[INSTALLER_CSS]] */
        /* [[/INSTALLER_CSS]] */
    </style>
    <script>
        window.INSTALLER_ICONS = {$iconsJson};

        (function() {
            var stored = localStorage.getItem('installer-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored ? stored === 'dark' : prefersDark) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-100 to-sky-50 dark:from-slate-900 dark:to-slate-800 text-slate-900 dark:text-slate-100 font-sans transition-colors duration-300">
    <div class="max-w-4xl mx-auto p-6 md:p-8">
        <div class="flex justify-end mb-2">
            <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="w-10 h-10 rounded-xl flex items-center justify-center bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:border-sky-300 dark:hover:border-sky-700 transition-all">
                <span id="theme-toggle-icon">🌙</span>
            </button>
        </div>
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-500 mb-4 shadow-lg shadow-sky-500/30">
                <span class="text-3xl">{$sidebarIcon}</span>
            </div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white mb-2">{$title}</h1>
            <p class="text-slate-500 dark:text-slate-400">{$sidebarDesc}</p>
            <p class="text-xs font-semibold text-sky-500 dark:text-sky-400 uppercase tracking-wide mt-2">{$sidebarLabel}</p>
        </div>

        {$stepIndicator}
        {$subStepIndicator}
        {$installTimer}

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 p-6 md:p-8">
            {$content}
        </div>

        <div class="text-center mt-6 text-xs text-slate-400 dark:text-slate-500">Application Installer v{$version}</div>
    </div>
    <script>
        (function() {
            var btn = document.getElementById('theme-toggle');
            var icon = document.getElementById('theme-toggle-icon');

            function sync() {
                var isDark = document.documentElement.classList.contains('dark');
                icon.textContent = isDark ? '☀️' : '🌙';
            }

            btn.addEventListener('click', function() {
                document.documentElement.classList.toggle('dark');
                var isDark = document.documentElement.classList.contains('dark');
                localStorage.setItem('installer-theme', isDark ? 'dark' : 'light');
                sync();
            });

            sync();
        })();
    </script>
</body>
</html>
HTML;
    }

    private function renderInstallTimer(int $currentStep): string
    {
        if ($currentStep !== 7) {
            return '';
        }

        return <<<'HTML'
        <div class="flex justify-center flex-wrap gap-4 md:gap-8 mb-6 p-3 md:p-4 bg-slate-100 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-sm" id="install-timer">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Started:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-start">--:--:--</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Elapsed:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-elapsed">0s</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Remaining:</span>
                <span class="font-mono text-slate-900 dark:text-white" id="timer-remaining">Calculating...</span>
            </div>
        </div>
        <script>
            (function() {
                var startTime = Date.now();
                var startDate = new Date(startTime);
                var startFormatted = startDate.toLocaleTimeString();
                document.getElementById('timer-start').textContent = startFormatted;

                function formatDuration(ms) {
                    var totalSeconds = Math.floor(ms / 1000);
                    if (totalSeconds < 60) {
                        return totalSeconds + 's';
                    }
                    var minutes = Math.floor(totalSeconds / 60);
                    var seconds = totalSeconds % 60;
                    if (minutes < 60) {
                        return minutes + 'm ' + seconds + 's';
                    }
                    var hours = Math.floor(minutes / 60);
                    minutes = minutes % 60;
                    return hours + 'h ' + minutes + 'm ' + seconds + 's';
                }

                function getProgress() {
                    var percentEl = document.getElementById('progress-percent');
                    if (!percentEl) {
                        return 0;
                    }
                    return (parseInt(percentEl.textContent, 10) || 0) / 100;
                }

                var timerInterval = setInterval(function() {
                    var elapsed = Date.now() - startTime;
                    document.getElementById('timer-elapsed').textContent = formatDuration(elapsed);

                    var progress = getProgress();
                    var remainingEl = document.getElementById('timer-remaining');

                    if (progress >= 1) {
                        remainingEl.textContent = 'Done';
                        clearInterval(timerInterval);
                    } else if (progress > 0.05) {
                        var estimatedTotal = elapsed / progress;
                        var remaining = estimatedTotal - elapsed;
                        remainingEl.textContent = '~' + formatDuration(remaining);
                    } else {
                        remainingEl.textContent = 'Calculating...';
                    }
                }, 1000);
            })();
        </script>
HTML;
    }

    private function renderStepIndicator(int $currentStep): string
    {
        if ($currentStep === 0) {
            return '';
        }

        $html = '<div class="flex justify-center flex-wrap gap-2 mb-6">';
        $visualNum = 0;
        foreach ($this->stepNames as $num => $name) {
            $visualNum++;
            $isSettingsGroup = ($num === 3);

            if ($isSettingsGroup) {
                if ($currentStep > 6) {
                    $class = 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
                    $icon = $this->statusIcon('check');
                } elseif (in_array($currentStep, $this->settingsSubSteps)) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = "{$visualNum}.";
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = "{$visualNum}.";
                }
            } else {
                if ($num < $currentStep) {
                    $class = 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
                    $icon = $this->statusIcon('check');
                } elseif ($num === $currentStep) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = "{$visualNum}.";
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = "{$visualNum}.";
                }
            }

            $html .= "<div class=\"flex items-center gap-2 px-3 py-1.5 rounded-full {$class} text-sm font-semibold\">{$icon} {$name}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    private function renderSubStepIndicator(int $currentStep): string
    {
        if (! in_array($currentStep, $this->settingsSubSteps)) {
            return '';
        }

        $html = '<div class="flex justify-center flex-wrap gap-2 mb-6">';
        foreach ($this->settingsSubSteps as $index => $step) {
            if ($step < $currentStep) {
                $class = 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700';
                $icon = $this->statusIcon('check');
            } elseif ($step === $currentStep) {
                $class = 'bg-sky-500 text-white shadow-lg shadow-sky-500/20';
                $icon = ($index + 1).'.';
            } else {
                $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                $icon = ($index + 1).'.';
            }
            $name = $this->settingsSubStepNames[$step];
            $num = $index + 1;
            $html .= "<div class=\"flex items-center gap-1.5 px-3 py-1.5 rounded-full {$class} text-xs font-semibold\">{$icon} {$name}</div>";
        }
        $html .= '</div>';

        return $html;
    }
}
