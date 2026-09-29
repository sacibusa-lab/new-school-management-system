@props(['steps' => [], 'current' => 1])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-y-3']) }}>
    @foreach ($steps as $index => $step)
        @php
            $number = $index + 1;
            $done = $number < $current;
            $active = $number === $current;
        @endphp

        <li class="flex items-center">
            <div class="flex items-center gap-2.5">
                <span @class([
                    'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1 ring-inset transition',
                    'bg-emerald-600 text-white ring-emerald-600' => $done,
                    'bg-brand-900 text-gold-300 ring-brand-900' => $active,
                    'bg-surface text-muted ring-line' => ! $done && ! $active,
                ])>
                    @if ($done)
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                        </svg>
                    @else
                        {{ $number }}
                    @endif
                </span>

                <span @class([
                    'text-sm font-medium',
                    'text-ink' => $active,
                    'text-muted' => ! $active,
                ])>{{ $step }}</span>
            </div>

            @unless ($loop->last)
                <span class="mx-3 hidden h-px w-8 bg-line sm:block"></span>
            @endunless
        </li>
    @endforeach
</ol>
