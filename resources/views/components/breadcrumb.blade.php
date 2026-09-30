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

                    @if ($loop->last || empty($crumb['route']) || request()->routeIs($crumb['route']))
                        {{-- Where you are is not a link, and neither is a crumb that
                             happens to point at this very page: a way out that leads
                             nowhere is worse than no way out. --}}
                        <span class="font-medium text-ink-soft" @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                    @else
                        <a href="{{ route($crumb['route']) }}" class="transition-colors hover:text-ink-soft">{{ $crumb['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
