<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use InstallerToolkit\Docs\Documentation as Guides;
use Livewire\Attributes\Url;

/**
 * The app's guides (docs/*.md) and changelog, read inside the admin panel.
 *
 * A buyer has no other copy: package:build ships docs/ and CHANGELOG.md only
 * when package-config.php sets 'include_docs' => true, and readme.html then
 * points here. Rendering, heading ids and links between guides are handled by
 * InstallerToolkit\Docs\Documentation.
 *
 * This file is a published stub — own and adjust the panel, navigation,
 * access and the guide list for your application.
 */
class Documentation extends Page
{
    /**
     * The guides in menu order: slug => [file relative to base_path(), menu label].
     * Guides whose file is missing are left out of the menu.
     *
     * @var array<string, array{file: string, label: string}>
     */
    protected const GUIDES = [
        'overview' => ['file' => 'docs/README.md', 'label' => 'Overview'],
        'changelog' => ['file' => 'CHANGELOG.md', 'label' => 'Changelog'],
    ];

    // Restricts the page to the "server" (admin) panel. Set null or override
    // shouldRegisterNavigation() to show it elsewhere.
    protected static ?string $panel = 'server';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.documentation';

    /**
     * Untyped on purpose: it comes from the query string, and a typed
     * property would fail to hydrate ?guide[]=x instead of falling back to
     * the first guide.
     */
    #[Url]
    public mixed $guide = null;

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()->getId() === 'server';
    }

    public function getTitle(): string
    {
        return 'Documentation';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $guides = new Guides(array_map(fn (array $guide): string => $guide['file'], static::GUIDES), base_path());
        $current = $guides->resolve(is_string($this->guide) ? $this->guide : null);

        return [
            'guides' => array_map(
                fn (array $guide): string => $guide['label'],
                array_intersect_key(static::GUIDES, $guides->available()),
            ),
            'current' => $current,
            'content' => $current === null
                ? null
                : new HtmlString($guides->render($current, fn (string $slug): string => static::getUrl(['guide' => $slug]))),
        ];
    }
}
