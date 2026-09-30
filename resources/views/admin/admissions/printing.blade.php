@extends('layouts.admin')

@section('title', 'Printing')

@section('content')

<p class="max-w-3xl text-sm text-muted">
    Everything the school prints, and where each one comes from. Nothing is printed from this page —
    each opens the list it belongs to, where the filters, the totals and the print button already are.
</p>

{{-- ================= The applicants ================= --}}
<div class="card-pad mt-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-ink">The applicants</h2>
            <p class="mt-1 max-w-2xl text-sm text-muted">
                Everybody who applied, whatever became of them. Filter it by class, status or session, then
                print the page or take the list away as a spreadsheet — the download carries exactly what
                the filters are showing. Each applicant's own admission letter is opened from their row.
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="{{ route('admin.applicants.index') }}" class="btn-secondary btn-sm">
                <x-nav-icon name="users" class="h-3.5 w-3.5" />
                Open the list
            </a>

            @can('admissions.export')
                <a href="{{ route('admin.applicants.export') }}" class="btn-secondary btn-sm">
                    <x-nav-icon name="download" class="h-3.5 w-3.5" />
                    Download CSV
                </a>
            @endcan
        </div>
    </div>

    <p class="mt-4 inline-flex items-center gap-2 rounded-xl bg-surface-2 px-3 py-2 text-xs text-ink-soft ring-1 ring-line">
        <x-nav-icon name="users" class="h-3.5 w-3.5 text-muted" />
        {{ number_format($applicants) }} applicant(s) on file
        @if ($session)
            · {{ $session->name }}
        @endif
    </p>
</div>

{{-- ================= The examination documents ================= --}}
<div class="card-pad mt-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-ink">For an examination</h2>
            <p class="mt-1 max-w-2xl text-sm text-muted">
                The sheet the school pins up, the letters it posts, the list it works from when a place comes
                free, and the cards the candidates bring to the gate.
            </p>
        </div>

        @if ($exams->isNotEmpty())
            {{-- One examination at a time: every document below is about the exam
                 chosen here, so the office picks once rather than four times. --}}
            <form method="GET" action="{{ route('admin.admissions.printing') }}"
                  class="flex shrink-0 items-end gap-2">
                <div>
                    <label for="exam" class="label">Examination</label>
                    <select id="exam" name="exam" class="input">
                        @foreach ($exams as $option)
                            <option value="{{ $option->id }}" @selected($exam?->id === $option->id)>
                                {{ $option->title }}@if ($option->level) — {{ $option->level->name }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="btn-secondary btn-sm">Show</button>
            </form>
        @endif
    </div>

    @if ($exam === null)
        <x-alert tone="info" class="mt-5">
            No examination this session yet. The merit list, the letters, the waiting list and the admit
            cards all come out of one, so they appear here once there is a paper to admit into.
        </x-alert>
    @else
        <p class="mt-4 inline-flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-surface-2 px-3 py-2 text-xs text-ink-soft ring-1 ring-line">
            <span><span class="font-semibold text-ink">{{ number_format($counts['admitted']) }}</span> admitted</span>
            <span class="text-muted">·</span>
            <span><span class="font-semibold text-ink">{{ number_format($counts['deferred']) }}</span> on the waiting list</span>
            <span class="text-muted">·</span>
            <span><span class="font-semibold text-ink">{{ number_format($counts['sat']) }}</span> sat the paper</span>
        </p>
    @endif

    <ul class="mt-5 divide-y divide-line">
        @can('admissions.view')
            <li class="flex flex-wrap items-start gap-4 py-4">
                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                    <x-nav-icon name="list" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium text-ink">Merit list</p>
                    <p class="mt-0.5 text-sm text-muted">
                        Everybody who sat the paper, in rank order with their marks — the sheet that goes up
                        on the noticeboard. Print it, or download it for the office's own records.
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2 self-center">
                    @if ($exam)
                        <a href="{{ route('admin.admissions.merit', $exam) }}" class="btn-secondary btn-sm">Open</a>
                        <a href="{{ route('admin.admissions.merit.csv', $exam) }}" class="btn-ghost btn-sm">CSV</a>
                    @else
                        <span class="text-xs text-muted">Needs an examination</span>
                    @endif
                </div>
            </li>
        @endcan

        @can('admissions.letters')
            <li class="flex flex-wrap items-start gap-4 py-4">
                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                    <x-nav-icon name="envelope" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium text-ink">Admission letters</p>
                    <p class="mt-0.5 text-sm text-muted">
                        One letter to a sheet for every candidate admitted from this paper, signed with the
                        signature uploaded under Admissions settings. A single letter is opened from the
                        applicant's row on the list above.
                    </p>
                </div>

                <div class="flex shrink-0 gap-2 self-center">
                    @if ($exam)
                        <a href="{{ route('admin.admissions.letters', $exam) }}" class="btn-secondary btn-sm">Open</a>
                    @else
                        <span class="text-xs text-muted">Needs an examination</span>
                    @endif
                </div>
            </li>
        @endcan

        @can('admissions.view')
            <li class="flex flex-wrap items-start gap-4 py-4">
                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                    <x-nav-icon name="users" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium text-ink">Waiting list</p>
                    <p class="mt-0.5 text-sm text-muted">
                        Those who passed but had no place, closest to the line first — the list the office
                        works from when a family turns an offer down.
                    </p>
                </div>

                <div class="flex shrink-0 gap-2 self-center">
                    @if ($exam)
                        <a href="{{ route('admin.admissions.waiting', $exam) }}" class="btn-secondary btn-sm">Open</a>
                    @else
                        <span class="text-xs text-muted">Needs an examination</span>
                    @endif
                </div>
            </li>
        @endcan

        @can('exams.view')
            <li class="flex flex-wrap items-start gap-4 py-4">
                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                    <x-nav-icon name="clipboard-check" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium text-ink">Admit cards</p>
                    <p class="mt-0.5 text-sm text-muted">
                        A card for every candidate sitting the paper, with their photograph and the subjects
                        they are entered for — what they show at the gate.
                    </p>
                </div>

                <div class="flex shrink-0 gap-2 self-center">
                    @if ($exam)
                        <a href="{{ route('admin.exams.admit-cards', $exam) }}" class="btn-secondary btn-sm">Open</a>
                    @else
                        <span class="text-xs text-muted">Needs an examination</span>
                    @endif
                </div>
            </li>
        @endcan
    </ul>
</div>
@endsection
