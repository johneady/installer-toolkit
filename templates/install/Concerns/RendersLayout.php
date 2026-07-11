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
     * separately in getStepLabel() so it can never drift out of sync with
     * the visual stepper.
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
     * The "X of N" label beside the step-dot row. The dots are rendered
     * one-per-screen — the Settings group expands to its own four dots in
     * renderHeaderStrip() — so there are exactly $totalSteps (9) dots, and
     * each screen's step number already equals its position in that row.
     * The label therefore mirrors the dots 1:1 (step 1 is "1 of 9", step 4
     * is "4 of 9", …) so the number shown can never disagree with the dot
     * the user is on.
     */
    private function getStepLabel(int $step): string
    {
        if ($step === 0) {
            return '';
        }

        return "{$step} of {$this->totalSteps}";
    }

    private function renderLayout(string $title, string $content, int $currentStep): void
    {
        $headerStrip = $this->renderHeaderStrip($title, $currentStep);
        $productName = htmlspecialchars(APP_NAME);
        $version = INSTALLER_VERSION;

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

        // Shared "copy to clipboard" behavior for the app-key and cron-command
        // copy buttons (cron and complete steps) — one definition so both stay
        // in lockstep instead of carrying their own copies.
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

        // Shared "Test Connection" AJAX handler for the database (step 3)
        // and mail (step 5) steps — identical request/response contract
        // (POST the form to `${step}&action=test`, expect {success, message}),
        // differing only in which form/button/result element and endpoint
        // to use, and the fallback error copy.
        function initTestConnectionButton(options) {
            var btn = document.getElementById(options.buttonId);
            var resultDiv = document.getElementById(options.resultId);
            var form = document.getElementById(options.formId);
            var originalLabel = btn.innerHTML;

            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.innerHTML = '<span class="h-spin">⏳</span> Testing...';
                resultDiv.classList.add('h-hidden');

                var formData = new FormData(form);

                fetch(options.endpoint, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        resultDiv.classList.remove('h-hidden');
                        resultDiv.className = data.success ? 'h-alert h-alert-good' : 'h-alert h-alert-bad';
                        resultDiv.innerHTML = '<div class="icon">' + (data.success ? window.INSTALLER_ICONS.check : window.INSTALLER_ICONS.x) + '</div>' +
                            '<div><p class="t">' + (data.success ? 'Connection Successful' : 'Connection Failed') + '</p><p class="d">' + data.message + '</p></div>';
                        btn.disabled = false;
                        btn.innerHTML = originalLabel;
                    })
                    .catch(function() {
                        resultDiv.classList.remove('h-hidden');
                        resultDiv.className = 'h-alert h-alert-bad';
                        resultDiv.innerHTML = '<div class="icon">' + window.INSTALLER_ICONS.x + '</div><div><p class="t">Error</p><p class="d">' + options.errorMessage + '</p></div>';
                        btn.disabled = false;
                        btn.innerHTML = originalLabel;
                    });
            });
        }
    </script>
</head>
<body class="h-body">
    <div class="h-shell">
        {$headerStrip}

        <div class="h-card">
            {$content}
        </div>

        <div class="h-footer">
            <div style="font-size:.82rem; font-weight:700; color:var(--h-ink);">{$productName}</div>
            <div>Application Installer v{$version}</div>
        </div>
    </div>

    <!-- Page Transition Overlay -->
    <div class="h-page-loader" id="page-loader">
        <div class="h-page-loader-spinner"></div>
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

        // Shows a full-page spinner while the browser waits on the next
        // page load, so multi-second server-side work (e.g. the mod_rewrite
        // self-request and filesystem checks on step 2) doesn't look like a
        // frozen/broken page. Only fires for plain navigations — AJAX calls
        // (data-ajax) and buttons that toggle in-page UI (type=button) are
        // excluded since they don't leave the page.
        (function() {
            var loader = document.getElementById('page-loader');

            function showLoader() {
                loader.classList.add('visible');
            }

            document.addEventListener('submit', function(e) {
                var form = e.target;
                if (form.tagName === 'FORM' && !form.hasAttribute('data-ajax')) {
                    showLoader();
                }
            });

            document.addEventListener('click', function(e) {
                var link = e.target.closest('a[href]');
                if (!link) {
                    return;
                }
                if (link.target === '_blank' || link.hasAttribute('download') || e.metaKey || e.ctrlKey || e.shiftKey) {
                    return;
                }
                showLoader();
            });

            // Restores the page if the browser serves it from bfcache on
            // back/forward nav, where the loader would otherwise still be
            // showing from the click that navigated away. event.persisted
            // is true only for bfcache restores, not normal loads — without
            // that check this would hide the loader on every fresh page
            // load, immediately after it appears.
            window.addEventListener('pageshow', function(e) {
                if (e.persisted) {
                    loader.classList.remove('visible');
                }
            });
        })();
    </script>
</body>
</html>
HTML;
    }

    /**
     * Single-row header: icon + title + description on the left, a compact
     * step-dot row + "Step X of N" label + theme toggle stacked on the
     * right. Replaces the old stack of icon/h1/description block, then a
     * separate row of full-size numbered badges, then a caption, then a
     * sub-step dot row — four vertically-stacked pieces collapsed into one
     * card so the actual form starts much higher on the page.
     *
     * The dot row uses one dot per top-level step, except the Settings
     * step (index 3) which is skipped in favor of 4 dots for its own
     * sub-steps — so the total dot count stays 9 regardless of whether
     * you're inside Settings or not, and the current position is always
     * visible without a second indicator.
     */
    private function renderHeaderStrip(string $title, int $currentStep): string
    {
        if ($currentStep === 0) {
            return $this->renderThemeToggleOnly();
        }

        $sidebar = $this->getSidebarInfo($currentStep);
        $icon = $sidebar['icon'];
        $desc = htmlspecialchars($sidebar['desc']);

        $dots = '';
        foreach ($this->stepNames as $num => $name) {
            if ($num === 3) {
                foreach ($this->settingsSubSteps as $subStep) {
                    $dots .= $this->renderStripDot($subStep < $currentStep, $subStep === $currentStep);
                }

                continue;
            }

            $dots .= $this->renderStripDot($num < $currentStep, $num === $currentStep);
        }

        $label = htmlspecialchars($this->getStepLabel($currentStep));

        return <<<HTML
        <div class="h-strip">
            <div class="h-strip-icon">{$icon}</div>
            <div class="h-strip-text">
                <h1>{$title}</h1>
                <p>{$desc}</p>
            </div>
            <div class="h-strip-side">
                <div class="h-strip-top">
                    <div class="h-strip-dots">{$dots}</div>
                    <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="h-theme-btn">
                        <span id="theme-toggle-icon">🌙</span>
                    </button>
                </div>
                <div class="h-strip-label">Step {$label}</div>
            </div>
        </div>
        HTML;
    }

    private function renderStripDot(bool $done, bool $now): string
    {
        $state = $done ? 'done' : ($now ? 'now' : '');

        return "<div class=\"h-sdot2 {$state}\"></div>";
    }

    /**
     * The "Already Installed" guard page (step 0) has no title/progress to
     * show, but still needs the theme toggle available.
     */
    private function renderThemeToggleOnly(): string
    {
        return <<<'HTML'
        <div class="h-topbar">
            <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="h-theme-btn">
                <span id="theme-toggle-icon">🌙</span>
            </button>
        </div>
        HTML;
    }
}
