<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InstallerToolkit\Update\RecentUpdateResults;
use InstallerToolkit\Update\UpdateResult;

/**
 * Launch page for the standalone updater.
 *
 * The upload, validation, progress, extraction, and rollback UIs all live
 * inside public/updater.php — a framework-free script shipped inside every
 * .update package. This page only shows the current version and recent
 * outcomes, and renders a button that mints a one-time handoff token and
 * redirects there.
 *
 * This file is a published stub — own and adjust the panel id, navigation,
 * and auth for your application. The engine is in InstallerToolkit\Update.
 */
class SystemUpdate extends Page
{
    // Restricts the page to the "server" (admin) panel. Set null or override
    // shouldRegisterNavigation() to show it elsewhere.
    protected static ?string $panel = 'server';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'System Update';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.system-update';

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()->getId() === 'server';
    }

    public function getTitle(): string
    {
        return 'System Update';
    }

    /**
     * @return list<UpdateResult>
     */
    public function recentResults(): array
    {
        return app(RecentUpdateResults::class)
            ->take((int) config('updates.updater.recent_results', 5));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'currentVersion' => (string) config('app.version', '—'),
            'launchUrl' => route('updater.launch'),
            'results' => $this->recentResults(),
        ];
    }
}
