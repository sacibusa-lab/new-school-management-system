@php
    // No `use` statement and no `@props` here: this is a plain @include, and a
    // `use` inside a compiled view is a parse error once a view is wrapped in a
    // function — which is exactly what BladeViewsCompileTest does to every view.
    $inputId = 'setting_' . $setting->key;
    $errorKey = 'settings.' . $setting->key . '.file';
    $isFavicon = $setting->key === 'school_favicon';
    $isSignature = $setting->key === 'signature_image';
    $isLetterhead = $setting->key === 'letterhead_image';
    // Named after the setting itself rather than assumed to be the logo: this is
    // also the crest, the favicon, and the Principal's signature.
    $what = strtolower($setting->label ?? 'image');
    $accept = \App\Services\Branding\BrandingService::ACCEPT;
    $maxMb = round(\App\Services\Branding\BrandingService::MAX_KB / 1024);
@endphp

{{-- A preview first, so the office can see what is live rather than trusting that
     last week's upload worked. --}}
<label for="{{ $inputId }}" class="label">{{ $setting->label ?? $setting->key }}</label>

<div class="flex flex-wrap items-start gap-4">
    {{-- A letterhead is a wide banner lying across a page, so it is previewed in a
         wide box; the crest and the favicon are squares and are previewed in one. --}}
    <div @class([
        'flex shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-surface',
        'h-24 w-64' => $isLetterhead,
        'h-24 w-24' => ! $isLetterhead,
    ])>
        @if ($setting->value)
            <img src="{{ asset('storage/' . $setting->value) }}"
                 alt="The current {{ $what }}"
                 class="h-full w-full object-contain p-2">
        @else
            <span class="px-2 text-center text-[11px] leading-tight text-muted">
                No {{ $what }} yet
            </span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <input id="{{ $inputId }}"
               type="file"
               name="settings[{{ $setting->key }}][file]"
               accept="{{ $accept }}"
               class="block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface-3 file:px-2.5 file:py-1.5 file:text-xs @error($errorKey) input-error @enderror">

        @if ($setting->value)
            {{-- Only offered when there is something to take back, and never
                 pre-ticked: losing the school's logo to a stray click would be
                 noticed only once parents saw the site. --}}
            <label class="mt-2 inline-flex items-center gap-2 text-xs text-ink-soft">
                <input type="checkbox"
                       name="settings[{{ $setting->key }}][remove]"
                       value="1"
                       class="h-3.5 w-3.5 rounded border-line text-rose-600 dark:text-rose-400 focus:ring-rose-500">
                Remove the current {{ $what }}
            </label>
        @endif

        @error($errorKey)
            <p class="error-text">{{ $message }}</p>
        @enderror

        <p class="hint">
            @if ($isSignature)
                A scan or a photograph of the signature itself, on a plain white background and
                cropped close to it. It is printed above the signatory's name on admission
                letters, and above the line on a result slip.
            @elseif ($isLetterhead)
                The whole letterhead as one picture — crest, school name, address and motto.
                It is printed across the top of the admission letter, the merit list and the
                admission status record. A wide banner works best; the documents keep its
                own proportions.
            @elseif ($isFavicon)
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
