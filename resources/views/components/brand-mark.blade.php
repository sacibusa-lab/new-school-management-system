@props([
    'size' => 'md',
])

@php
    $school = \App\Models\Setting::get('school_name', config('saci.school_name'));
    $initials = collect(preg_split('/\s+/', (string) $school))
        ->filter()
        ->take(2)
        ->map(fn ($word) => strtoupper(substr($word, 0, 1)))
        ->implode('');

    $dimensions = match ($size) {
        'sm' => 'h-9 w-9 text-sm rounded-xl',
        'lg' => 'h-12 w-12 text-lg rounded-2xl',
        default => 'h-10 w-10 text-base rounded-xl',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-brand-900 font-display font-semibold text-gold-300 shadow-sm {$dimensions}"]) }}
      aria-hidden="true">{{ $initials ?: 'SA' }}</span>
