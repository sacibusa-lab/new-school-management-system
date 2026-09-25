@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,
    'tone' => 'brand',
    'href' => null,
])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/10',
        'gold' => 'bg-gold-50 text-gold-700 ring-gold-600/15',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-600/10',
        'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/10',
    ];

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'group card block p-5 transition ' . ($href ? 'hover:shadow-lift hover:-translate-y-0.5' : '')]) }}>

    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-500">{{ $label }}</p>
            <p class="mt-2 font-display text-2xl font-semibold text-slate-900">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
            @endif
        </div>

        @if ($icon)
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $tones[$tone] ?? $tones['brand'] }}">
                <x-nav-icon :name="$icon" />
            </span>
        @endif
    </div>
</{{ $tag }}>
