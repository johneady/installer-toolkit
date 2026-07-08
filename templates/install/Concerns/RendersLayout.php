<?php

trait RendersLayout
{
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

    private function getSidebarInfo(int $step): array
    {
        return match ($step) {
            1 => [
                'icon' => '📄',
                'desc' => 'Please read and accept the End User License Agreement before proceeding with the installation.',
                'label' => 'Step 1 of 6',
            ],
            2 => [
                'icon' => '🔍',
                'desc' => 'Verifying that your server meets all the requirements needed to run successfully.',
                'label' => 'Step 2 of 6',
            ],
            3 => [
                'icon' => '🗄️',
                'desc' => 'Enter the credentials for the database that you will use to store data.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            4 => [
                'icon' => '⚙️',
                'desc' => 'Configure your application name, URL, timezone, and the amount of initial data to load.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            5 => [
                'icon' => '✉️',
                'desc' => 'Set up how this application sends email notifications. You can always update this later in the admin panel.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            6 => [
                'icon' => '👤',
                'desc' => 'Create the administrator account you will use to manage your website.',
                'label' => 'Step 3 of 6 — Settings',
            ],
            7 => [
                'icon' => '🚀',
                'desc' => 'Sit tight — The application is being installed on your server. Do not close this page.',
                'label' => 'Step 4 of 6',
            ],
            8 => [
                'icon' => '⏰',
                'desc' => 'Set up a scheduled task so you can run background jobs automatically.',
                'label' => 'Step 5 of 6',
            ],
            9 => [
                'icon' => '🎉',
                'desc' => 'Installation is complete. Your application is ready to go!',
                'label' => 'Step 6 of 6',
            ],
            default => ['icon' => '🐾', 'desc' => '', 'label' => ''],
        };
    }

    private function renderLayout(string $title, string $content, int $currentStep): void
    {
        $stepIndicator = $this->renderStepIndicator($currentStep);
        $subStepIndicator = $this->renderSubStepIndicator($currentStep);
        $version = INSTALLER_VERSION;
        $sidebar = $this->getSidebarInfo($currentStep);
        $sidebarIcon = $sidebar['icon'];
        $sidebarDesc = htmlspecialchars($sidebar['desc']);
        $sidebarLabel = htmlspecialchars($sidebar['label']);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Installer - {$title}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
                    },
                    animation: {
                        'spin': 'spin 1s linear infinite',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'bounce-slow': 'bounce 2s infinite',
                    },
                }
            }
        }
    </script>
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
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-100 to-sky-50 dark:from-slate-900 dark:to-slate-800 text-slate-900 dark:text-slate-100 font-sans transition-colors duration-300">
    <div class="max-w-4xl mx-auto p-6 md:p-8">
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

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 p-6 md:p-8">
            {$content}
        </div>

        <div class="text-center mt-6 text-xs text-slate-400 dark:text-slate-500">Application Installer v{$version}</div>
    </div>
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
                    var totalTasks = typeof tasks !== 'undefined' ? tasks.length : 0;
                    if (totalTasks === 0) {
                        return 0;
                    }
                    var completed = document.querySelectorAll('.task-item.task-done').length;
                    return completed / totalTasks;
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
                    $icon = '✓';
                } elseif (in_array($currentStep, $this->settingsSubSteps)) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = $visualNum;
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = $visualNum;
                }
            } else {
                if ($num < $currentStep) {
                    $class = 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
                    $icon = '✓';
                } elseif ($num === $currentStep) {
                    $class = 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white shadow-lg shadow-sky-500/20';
                    $icon = $visualNum;
                } else {
                    $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                    $icon = $visualNum;
                }
            }

            $html .= "<div class=\"flex items-center gap-2 px-3 py-1.5 rounded-full {$class} text-sm font-semibold\">{$icon}. {$name}</div>";
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
                $icon = '✓';
            } elseif ($step === $currentStep) {
                $class = 'bg-sky-500 text-white shadow-lg shadow-sky-500/20';
                $icon = $index + 1;
            } else {
                $class = 'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-600';
                $icon = $index + 1;
            }
            $name = $this->settingsSubStepNames[$step];
            $num = $index + 1;
            $html .= "<div class=\"flex items-center gap-1.5 px-3 py-1.5 rounded-full {$class} text-xs font-semibold\">{$icon}. {$name}</div>";
        }
        $html .= '</div>';

        return $html;
    }
}
