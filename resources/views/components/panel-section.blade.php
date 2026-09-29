@props([
    'number',
    'title',
    'meta' => null,
    'description' => null,
    'divider' => true,
])

{{-- A numbered dashboard section, separated from the one above by a rule with
     generous breathing room either side. --}}
<section {{ $attributes->merge([
    'class' => $divider
        ? 'mt-16 border-t border-line pt-10 sm:mt-20 sm:pt-12'
        : '',
]) }}>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-900 font-display text-sm font-semibold text-gold-300">
            {{ $number }}
        </span>

        <h2 class="font-display text-xl font-semibold text-ink">{{ $title }}</h2>

        <span class="hidden h-px flex-1 bg-line sm:block"></span>

        @if ($meta)
            <p class="text-xs font-medium uppercase tracking-wider text-muted">{{ $meta }}</p>
        @endif
    </div>

    @if ($description)
        <p class="mt-2.5 max-w-3xl text-sm leading-relaxed text-ink-soft">{{ $description }}</p>
    @endif

    <div class="mt-8">
        {{ $slot }}
    </div>
</section>
