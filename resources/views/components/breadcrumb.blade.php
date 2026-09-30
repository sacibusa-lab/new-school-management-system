@props(['trail' => []])

@php
    /*
     * Where a page sits.
     *
     * A trail of ['label' => string, 'route' => ?string], outermost first, with the
     * page you are on last. A trail with one item is not a trail — it would say
     * only what the title underneath already says — so nothing is drawn for it,
     * and a page with nothing to go back to needs no code at all: it simply does
     * not pass one.
     */
    $trail = array_values(array_filter($trail));
@endphp

@if (count($trail) > 1)
    <nav aria-label="Breadcrumb" {{ $attributes }}>
        <ol class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
            @foreach ($trail as $crumb)
                <li class="flex items-center gap-1.5">
                    @unless ($loop->first)
                        <x-nav-icon name="chevron-right" class="h-3 w-3 text-muted" />
                    @endunless

                    @if ($loop->last || empty($crumb['route']))
                        {{-- Where you are is not a link: a link to this page is a way
                             out that leads nowhere. --}}
                        <span class="font-medium text-ink-soft" aria-current="page">{{ $crumb['label'] }}</span>
                    @else
                        <a href="{{ route($crumb['route']) }}" class="transition-colors hover:text-ink-soft">{{ $crumb['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
