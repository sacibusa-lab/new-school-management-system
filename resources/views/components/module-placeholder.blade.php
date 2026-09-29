@props([
    'title',
    'icon' => 'grid',
    'note' => null,
])

{{--
    A page of the Students & Results menu that has not been built yet.

    Deliberately not an empty state: an empty state means "there is nothing to
    show", and somebody reading one would go looking for a filter they had got
    wrong. This says the page itself does not exist yet, so the office knows to
    ask rather than to hunt.
--}}
<div {{ $attributes->merge(['class' => 'card flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
        <x-nav-icon :name="$icon" class="h-7 w-7" />
    </span>

    <p class="mt-4 font-display text-lg font-semibold text-slate-900">{{ $title }}</p>

    <p class="mt-1.5 max-w-md text-sm text-slate-500">
        This page has not been built yet — it is one of the Students &amp; Results pages being
        created one at a time. Nothing is wrong: there is simply nothing here to look at.
    </p>

    @if ($note)
        <p class="mt-4 max-w-md rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
            {{ $note }}
        </p>
    @endif
</div>
