@php
    // No `use` statement and no `@props` here: this is a plain @include, and a
    // `use` inside a compiled view is a parse error once a view is wrapped in a
    // function — which is exactly what BladeViewsCompileTest does to every view.
    $inputId = 'setting_' . $setting->key;
    $errorKey = 'settings.' . $setting->key . '.file';
    $isFavicon = $setting->key === 'school_favicon';
    $what = $isFavicon ? 'favicon' : 'logo';
    $accept = \App\Services\Branding\BrandingService::ACCEPT;
    $maxMb = round(\App\Services\Branding\BrandingService::MAX_KB / 1024);
@endphp

{{-- A preview first, so the office can see what is live rather than trusting that
     last week's upload worked. --}}
<label for="{{ $inputId }}" class="label">{{ $setting->label ?? $setting->key }}</label>

<div class="flex flex-wrap items-start gap-4">
    <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white">
        @if ($setting->value)
            <img src="{{ asset('storage/' . $setting->value) }}"
                 alt="The current {{ $what }}"
                 class="h-full w-full object-contain p-2">
        @else
            <span class="px-2 text-center text-[11px] leading-tight text-slate-400">
                No {{ $what }} yet
            </span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <input id="{{ $inputId }}"
               type="file"
               name="settings[{{ $setting->key }}][file]"
               accept="{{ $accept }}"
               class="block w-full text-xs text-slate-600 file:mr-2 file:rounded-md file:border-0 file:bg-slate-100 file:px-2.5 file:py-1.5 file:text-xs @error($errorKey) input-error @enderror">

        @if ($setting->value)
            {{-- Only offered when there is something to take back, and never
                 pre-ticked: losing the school's logo to a stray click would be
                 noticed only once parents saw the site. --}}
            <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox"
                       name="settings[{{ $setting->key }}][remove]"
                       value="1"
                       class="h-3.5 w-3.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                Remove the current {{ $what }}
            </label>
        @endif

        @error($errorKey)
            <p class="error-text">{{ $message }}</p>
        @enderror

        <p class="hint">
            @if ($isFavicon)
                The little picture on the browser tab. A square PNG works best, 512 × 512 or smaller.
            @else
                Shown in the site header, the footer and the admin sidebar.
                A square image with a transparent or white background looks best.
            @endif
            PNG, JPG, WEBP, SVG or ICO, up to {{ $maxMb }} MB.
            @if ($setting->value)
                Choosing another file replaces this one.
            @endif
        </p>
    </div>
</div>
