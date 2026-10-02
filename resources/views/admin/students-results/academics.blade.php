@extends('layouts.admin')

{{-- No subtitle: the breadcrumb in the header already says where this sits. --}}
@section('title', 'Academic')

@section('content')
    {{--
        Academic is a section rather than a single screen: classes, the subjects
        they sit, the timetable they run on, and what happens to a class at the end
        of the year. Its pages are drawn from the controller's own list — the same
        one the sidebar uses — so adding a fifth cannot leave one of the two behind.
    --}}
    <div class="card overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">How the school is organised</p>
            <p class="mt-1 text-sm text-muted">
                The classes the school runs, the subjects each one sits, the timetable that decides when,
                and where a class goes at the end of the session. They are being built one at a time, so a
                page that is not ready says so rather than looking broken.
            </p>
        </div>

        <ul class="divide-y divide-line">
            @foreach ($children as $child)
                <li>
                    <a href="{{ route($child['route']) }}"
                       class="flex items-start gap-4 px-5 py-4 transition-colors hover:bg-surface-2">
                        <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                            <x-nav-icon :name="$child['icon']" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-ink">{{ $child['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-muted">{{ $child['note'] }}</span>
                        </span>

                        <x-nav-icon name="chevron-right" class="mt-1.5 h-4 w-4 shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
