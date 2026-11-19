<?php

declare(strict_types=1);

namespace InstallerToolkit\Docs;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders an app's Markdown guides (docs/ and CHANGELOG.md, shipped when
 * package-config sets 'include_docs') for a page inside the app.
 *
 * Framework-free apart from league/commonmark, so the published Filament page
 * stays a thin wrapper: it lists the guides and asks for one rendered.
 *
 * - Guides whose file is missing are left out, so a build without the
 *   changelog loses that entry rather than the whole page.
 * - Headings get GitHub-style ids, so links written for GitHub (`#updating`)
 *   still land.
 * - Relative links that resolve, from the guide's own folder, to another
 *   guide's file (`stripe.md#keys`, `../CHANGELOG.md`) are pointed at the
 *   page through $urlFor. Other links are left alone.
 * - Raw HTML in the Markdown is stripped and unsafe links (javascript: and
 *   the like) are dropped: the changelog is written from commit messages.
 */
final class Documentation
{
    /**
     * @param  array<string, string>  $guides  slug => path to the Markdown file, relative to $basePath, in menu order
     */
    public function __construct(
        private readonly array $guides,
        private readonly string $basePath,
    ) {}

    /**
     * The guides whose file exists, in menu order.
     *
     * @return array<string, string> slug => relative path
     */
    public function available(): array
    {
        return array_filter($this->guides, fn (string $file): bool => is_file($this->path($file)));
    }

    /**
     * The slug to show for a requested one: itself when it is an available
     * guide, otherwise the first available guide (null when there is none).
     */
    public function resolve(?string $slug): ?string
    {
        $available = $this->available();

        if ($slug !== null && array_key_exists($slug, $available)) {
            return $slug;
        }

        return array_key_first($available);
    }

    /**
     * The guide's HTML. $urlFor turns a guide's slug into the page's URL for it.
     *
     * @param  Closure(string): string  $urlFor
     */
    public function render(string $slug, Closure $urlFor): string
    {
        $available = $this->available();

        if (! array_key_exists($slug, $available)) {
            throw new \InvalidArgumentException("No guide named '{$slug}'.");
        }

        $file = $available[$slug];
        $html = $this->converter()->convert((string) file_get_contents($this->path($file)))->getContent();

        $slugsByFile = array_flip(array_map(fn (string $guide): string => $this->normalize($guide) ?? $guide, $available));

        return (string) preg_replace_callback(
            '/href="(?<target>[^"#]*\.md)(?<fragment>#[^"]*)?"/',
            function (array $match) use ($file, $slugsByFile, $urlFor): string {
                $target = html_entity_decode($match['target'], ENT_QUOTES);

                // Only relative links: not https:, mailto:, //host or /root.
                if (preg_match('/^(?:[a-zA-Z][a-zA-Z0-9+.-]*:|\/)/', $target) === 1) {
                    return $match[0];
                }

                $resolved = $this->normalize(dirname($file).'/'.$target);

                if ($resolved === null || ! array_key_exists($resolved, $slugsByFile)) {
                    return $match[0];
                }

                $url = htmlspecialchars($urlFor($slugsByFile[$resolved]), ENT_QUOTES);

                return 'href="'.$url.($match['fragment'] ?? '').'"';
            },
            $html,
        );
    }

    /**
     * A relative path with `.` and `..` resolved, or null when it climbs
     * above the base path.
     */
    private function normalize(string $path): ?string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        return new MarkdownConverter($environment);
    }

    private function path(string $file): string
    {
        return rtrim($this->basePath, '/').'/'.ltrim($file, '/');
    }
}
