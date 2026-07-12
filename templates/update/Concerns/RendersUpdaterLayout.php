<?php

/**
 * Updater-specific layout pieces: the screen sidebar copy and the header
 * strip. The shared page shell, icons, and theme handling live in
 * templates/shared/Concerns/RendersChrome.php.
 */
trait RendersUpdaterLayout
{
    private function toolLabel(): string
    {
        return 'Application Updater';
    }

    private function toolVersion(): string
    {
        return UPDATER_VERSION;
    }

    private function getSidebarInfo(int $step): array
    {
        return match ($step) {
            1 => [
                'icon' => '📦',
                'desc' => 'Check your server, review past updates, and upload a new .update package.',
            ],
            2 => [
                'icon' => '🔍',
                'desc' => 'The package has been validated. Review the details before applying the update.',
            ],
            3 => [
                'icon' => '🚀',
                'desc' => 'Sit tight — the application is being backed up and updated. Do not close this page.',
            ],
            4 => [
                'icon' => '🎉',
                'desc' => 'The update is complete and your application is live again.',
            ],
            default => ['icon' => '⚙️', 'desc' => ''],
        };
    }

    private function renderHeaderStrip(string $title, int $currentStep): string
    {
        if ($currentStep === 0) {
            return $this->renderThemeToggleOnly();
        }

        $sidebar = $this->getSidebarInfo($currentStep);
        $icon = $sidebar['icon'];
        $desc = htmlspecialchars($sidebar['desc']);

        $dots = '';
        foreach (array_keys($this->stepNames) as $num) {
            $dots .= $this->renderStripDot($num < $currentStep, $num === $currentStep);
        }

        $label = "{$currentStep} of {$this->totalSteps}";

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

    private function renderRequirementRow(array $r): string
    {
        $icon = $this->statusIcon($r['passed'] ? 'check' : ($r['critical'] ? 'x' : 'warning'), 16);
        $rowClass = $r['passed'] ? 'good' : ($r['critical'] ? 'bad' : 'warn');
        $badge = $r['passed'] ? 'Passed' : ($r['critical'] ? 'Critical' : 'Warning');
        $name = htmlspecialchars($r['name']);
        $detail = htmlspecialchars($r['detail']);

        return <<<HTML
        <div class="h-reqrow {$rowClass}">
            <div class="icon">{$icon}</div>
            <div class="body">
                <p class="t">{$name}</p>
                <p class="d">{$detail}</p>
            </div>
            <span class="badge">{$badge}</span>
        </div>
        HTML;
    }

    /**
     * Renders a set of related checks (e.g. all PHP version/extension
     * checks) as a single bordered box with one summary badge, instead of
     * one h-reqrow per check.
     */
    private function renderRequirementGroup(string $title, array $items): string
    {
        $anyCritical = false;
        $allPassed = true;

        $rows = '';
        foreach ($items as $r) {
            $icon = $this->statusIcon($r['passed'] ? 'check' : ($r['critical'] ? 'x' : 'warning'), 14);
            $rowClass = $r['passed'] ? 'good' : ($r['critical'] ? 'bad' : 'warn');
            $name = htmlspecialchars($r['name']);
            $detail = htmlspecialchars($r['detail']);

            if (! $r['passed']) {
                $allPassed = false;
                if ($r['critical']) {
                    $anyCritical = true;
                }
            }

            $rows .= <<<HTML
            <div class="h-reqgroup-item {$rowClass}">
                <div class="icon">{$icon}</div>
                <div class="body">
                    <p class="t">{$name}</p>
                    <p class="d">{$detail}</p>
                </div>
            </div>
            HTML;
        }

        $groupClass = $allPassed ? 'good' : ($anyCritical ? 'bad' : 'warn');
        $badge = $allPassed ? 'Passed' : ($anyCritical ? 'Critical' : 'Warning');
        $title = htmlspecialchars($title);

        return <<<HTML
        <div class="h-reqgroup {$groupClass}">
            <div class="h-reqgroup-head">
                <p class="t">{$title}</p>
                <span class="badge">{$badge}</span>
            </div>
            <div class="h-reqgroup-body">{$rows}</div>
        </div>
        HTML;
    }
}
