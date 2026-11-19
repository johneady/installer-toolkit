{{--
    Published stub. Tailwind classes here are only generated when the panel's
    theme scans resources/views/filament (an @source line in its theme.css).

    The [&_code] and [&_blockquote] variants undo two fi-prose defaults that
    suit articles but not guides: literal backticks around inline code, and
    italic blockquotes wrapped in quote marks. They are important (!) because
    fi-prose's own selectors are as or more specific.
--}}
<x-filament-panels::page>
    <div class="flex flex-col gap-6 lg:flex-row">
        <nav class="shrink-0 lg:w-56" aria-label="Guides">
            <ul class="flex flex-wrap gap-2 lg:flex-col">
                @foreach ($guides as $slug => $label)
                    <li>
                        <a
                            href="{{ static::getUrl(['guide' => $slug]) }}"
                            @class([
                                'block rounded-lg px-3 py-2 text-sm font-medium',
                                'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $slug === $current,
                                'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $slug !== $current,
                            ])
                            @if ($slug === $current) aria-current="page" @endif
                        >{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <article class="fi-prose min-w-0 flex-1 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 [&_blockquote]:not-italic! [&_blockquote_p]:before:content-none! [&_blockquote_p]:after:content-none! [&_code]:before:content-none! [&_code]:after:content-none!">
            @if ($content === null)
                <p>No guides are installed.</p>
            @else
                {{ $content }}
            @endif
        </article>
    </div>
</x-filament-panels::page>
