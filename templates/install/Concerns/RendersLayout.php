<?php

trait RendersLayout
{
    /**
     * Inline SVG status icons (check / x / warning triangle) used in place
     * of emoji, which render inconsistently across OS/browser emoji fonts.
     * All use currentColor so they inherit whatever text-color class wraps
     * them. Kept here (rather than per-trait) since every step includes
     * this trait and needs the same three icons.
     *
     * Size is applied via explicit width/height attributes rather than a
     * class, since these SVGs have no intrinsic size (only a viewBox) —
     * without one a browser falls back to its default SVG size (often
     * ~300x150), rendering the icon comically oversized.
     */
    private function statusIcon(string $type, int $size = 20): string
    {
        return match ($type) {
            'check' => "<svg width=\"{$size}\" height=\"{$size}\" class=\"inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"3\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M4.5 12.75l6 6 9-13.5\" /></svg>",
            'x' => "<svg width=\"{$size}\" height=\"{$size}\" class=\"inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"3\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M6 18L18 6M6 6l12 12\" /></svg>",
            'warning' => "<svg width=\"{$size}\" height=\"{$size}\" class=\"inline-block\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z\" /></svg>",
            default => '',
        };
    }

    private function renderErrors(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        $html = '<div class="h-errors"><ul>';
        foreach ($this->errors as $error) {
            $html .= '<li>'.htmlspecialchars($error).'</li>';
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
     * Computes "X of N" (optionally suffixed with the current settings
     * sub-step name) directly from $stepNames/$settingsSubSteps — the
     * same data renderProgress() uses — so the two displays can't
     * disagree.
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

        $label = "{$matchedNum} of {$total}";

        if (in_array($step, $this->settingsSubSteps)) {
            $label .= ': '.$this->settingsSubStepNames[$step];
        }

        return $label;
    }

    private function renderLayout(string $title, string $content, int $currentStep): void
    {
        $progress = $this->renderProgress($currentStep);
        $installTimer = $this->renderInstallTimer($currentStep);
        $version = INSTALLER_VERSION;
        $sidebar = $this->getSidebarInfo($currentStep);
        $sidebarIcon = $sidebar['icon'];
        $sidebarDesc = htmlspecialchars($sidebar['desc']);

        // Exposed globally so per-step inline scripts (DB/mail connection
        // tests, task list, etc.) can build status HTML without duplicating
        // the SVG markup — same source as statusIcon() above. 'small' variants
        // are pre-sized here rather than shrunk client-side, so callers never
        // depend on the exact class string statusIcon() happens to emit.
        $iconsJson = json_encode([
            'check' => $this->statusIcon('check'),
            'x' => $this->statusIcon('x'),
            'warning' => $this->statusIcon('warning'),
            'checkSmall' => $this->statusIcon('check', 14),
            'xSmall' => $this->statusIcon('x', 14),
        ]);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Installer - {$title}</title>
    <style>
        /* [[INSTALLER_CSS]] */
        /* [[/INSTALLER_CSS]] */
    </style>
    <script>
        window.INSTALLER_ICONS = {$iconsJson};

        (function() {
            var stored = localStorage.getItem('installer-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var isDark = stored ? stored === 'dark' : prefersDark;
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        })();
    </script>
</head>
<body class="h-body">
    <div class="h-shell">
        <div class="h-topbar">
            <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="h-theme-btn">
                <span id="theme-toggle-icon">🌙</span>
            </button>
        </div>
        <div class="h-head">
            <div class="h-head-icon">{$sidebarIcon}</div>
            <h1>{$title}</h1>
            <p>{$sidebarDesc}</p>
        </div>

        {$progress}
        {$installTimer}

        <div class="h-card">
            {$content}
        </div>

        <div class="h-footer">Application Installer v{$version}</div>
    </div>
    <script>
        (function() {
            var btn = document.getElementById('theme-toggle');
            var icon = document.getElementById('theme-toggle-icon');

            function sync() {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                icon.textContent = isDark ? '☀️' : '🌙';
            }

            btn.addEventListener('click', function() {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
                localStorage.setItem('installer-theme', isDark ? 'light' : 'dark');
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
        <div class="h-timer" id="install-timer">
            <div>
                <span>Started:</span>
                <span id="timer-start">--:--:--</span>
            </div>
            <div>
                <span>Elapsed:</span>
                <span id="timer-elapsed">0s</span>
            </div>
            <div>
                <span>Remaining:</span>
                <span id="timer-remaining">Calculating...</span>
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

    /**
     * Top-level progress: a row of circular numbered badges joined by
     * connector lines (done = filled solid, current = outlined + tinted,
     * upcoming = plain outline), plus a text caption naming the current
     * step. When the current step falls inside the Settings group, a
     * second smaller dot row renders underneath for the 4 sub-steps
     * (see renderSubProgress()) so users can see exactly where they are
     * within Settings without a second row of full-size badges.
     */
    private function renderProgress(int $currentStep): string
    {
        if ($currentStep === 0) {
            return '';
        }

        $total = count($this->stepNames);
        $badges = '';
        $visualNum = 0;
        $currentName = '';

        foreach ($this->stepNames as $num => $name) {
            $visualNum++;
            $isSettingsGroup = ($num === 3);
            $isCurrentGroup = $isSettingsGroup
                ? in_array($currentStep, $this->settingsSubSteps)
                : ($num === $currentStep);
            $isDone = $isSettingsGroup ? ($currentStep > 6) : ($num < $currentStep);

            if ($isDone) {
                $state = 'done';
                $inner = $this->statusIcon('check', 14);
            } elseif ($isCurrentGroup) {
                $state = 'now';
                $inner = (string) $visualNum;
                $currentName = $name;
            } else {
                $state = '';
                $inner = (string) $visualNum;
            }

            if ($visualNum > 1) {
                $lineDone = $isDone || $isCurrentGroup ? 'done' : '';
                // A connector is "done" once we've reached or passed the step it leads to.
                $badges .= "<div class=\"h-pline {$lineDone}\"></div>";
            }

            $badges .= "<div class=\"h-pstep {$state}\">{$inner}</div>";
        }

        if ($currentName === '') {
            // currentStep matched neither a top-level nor a settings-group step
            // (defensive — keeps the caption blank rather than stale).
            $currentName = $this->stepNames[$currentStep] ?? '';
        }

        $label = $this->getStepLabel($currentStep);
        $caption = $label !== '' ? "<div class=\"h-progress-label\"><b>{$currentName}</b> — Step ".htmlspecialchars($label).'</div>' : '';

        $sub = $this->renderSubProgress($currentStep);

        return "<div class=\"h-progress\">{$badges}</div>{$caption}{$sub}";
    }

    /**
     * Small dot row for the 4 settings sub-steps (Database / Application /
     * Email / Admin Account), rendered only while inside that group.
     */
    private function renderSubProgress(int $currentStep): string
    {
        if (! in_array($currentStep, $this->settingsSubSteps)) {
            return '';
        }

        $dots = '';
        foreach ($this->settingsSubSteps as $step) {
            if ($step < $currentStep) {
                $state = 'done';
            } elseif ($step === $currentStep) {
                $state = 'now';
            } else {
                $state = '';
            }
            $dots .= "<div class=\"h-sdot {$state}\"></div>";
        }

        return "<div class=\"h-subprogress\">{$dots}</div>";
    }
}
