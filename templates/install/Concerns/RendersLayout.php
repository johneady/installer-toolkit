<?php

/**
 * Installer-specific layout pieces: the step sidebar copy, the "X of N"
 * label, and the header strip with its Settings sub-step dot expansion.
 * The shared page shell, icons, and theme handling live in
 * templates/shared/Concerns/RendersChrome.php.
 */
trait RendersLayout
{
    private function toolLabel(): string
    {
        return 'Application Installer';
    }

    private function toolVersion(): string
    {
        return INSTALLER_VERSION;
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
}
