@props([
    'size' => 'md',
])

@php
    $name = \App\Models\Setting::get('school_name', config('saci.school_name'));
    $logo = \App\Models\Setting::get('school_logo');

    // One rule, in one place — see BrandingService::monogram(). The same letters come out on
    // the sidebar, on an admit card and on a printed payment slip.
    $initials = \App\Services\Branding\BrandingService::monogram($name);

    $dimensions = match ($size) {
        'sm' => 'h-9 w-9 text-sm rounded-xl',
        'lg' => 'h-12 w-12 text-lg rounded-2xl',
        'xl' => 'h-14 w-14 text-xl rounded-2xl',
        default => 'h-10 w-10 text-base rounded-xl',
    };
@endphp

@if ($logo)
    {{-- The logo sits on its own light tile rather than straight on the background.
         The admin sidebar is navy, and a logo drawn in dark ink on a transparent
         background would vanish there while looking perfectly fine on the site. --}}
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center overflow-hidden bg-white p-0.5 ring-1 ring-black/5 {$dimensions}"]) }}>
        <img src="{{ asset('storage/' . $logo) }}"
             alt="{{ $name }}"
             class="h-full w-full object-contain">
    </span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-brand-900 font-display font-semibold text-gold-300 shadow-sm {$dimensions}"]) }}
          aria-hidden="true">{{ $initials ?: 'SA' }}</span>
@endif
