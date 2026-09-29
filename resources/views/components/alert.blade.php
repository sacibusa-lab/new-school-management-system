@props([
    'tone' => 'info',
    'title' => null,
])

@php
    $tones = [
        'info' => 'bg-brand-50 dark:bg-brand-900/30 text-brand-900 dark:text-brand-100 ring-brand-600/15 [&_svg]:text-brand-600',
        'success' => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-100 ring-emerald-600/15 [&_svg]:text-emerald-600 dark:text-emerald-400',
        'warning' => 'bg-gold-50 dark:bg-gold-950/40 text-gold-900 ring-gold-600/20 dark:ring-gold-400/20 [&_svg]:text-gold-600',
        'danger' => 'bg-rose-50 dark:bg-rose-950/40 text-rose-900 dark:text-rose-100 ring-rose-600/15 [&_svg]:text-rose-600 dark:text-rose-400',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex gap-3 rounded-xl p-4 text-sm ring-1 ring-inset ' . ($tones[$tone] ?? $tones['info'])]) }}
     role="alert">
    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
        @if ($tone === 'success')
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        @elseif ($tone === 'warning')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        @elseif ($tone === 'danger')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
        @else
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
        @endif
    </svg>

    <div class="flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5' => (bool) $title])>{{ $slot }}</div>
    </div>
</div>
