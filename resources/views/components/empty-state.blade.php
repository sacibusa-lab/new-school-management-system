@props([
    'title',
    'description' => null,
    'icon' => 'users',
])

<div {{ $attributes->merge(['class' => 'card flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-3 text-muted">
        <x-nav-icon :name="$icon" class="h-7 w-7" />
    </span>

    <p class="mt-4 text-base font-semibold text-ink">{{ $title }}</p>

    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-muted">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
