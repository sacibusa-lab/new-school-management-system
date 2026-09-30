@php
    // One card per candidate, four to a sheet, so the office can hand them out at
    // the gate on the morning of the examination.
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admit cards — {{ $exam->title }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 print:bg-white">

<div class="mx-auto max-w-5xl p-6 print:max-w-none print:p-0">

    <div class="no-print mb-5 flex flex-wrap items-center gap-3">
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">
            Print {{ $candidates->count() }} card(s)
        </button>
        <a href="{{ route('admin.exams.show', $exam) }}" class="btn-ghost btn-sm">&larr; Back to the examination</a>
    </div>

    <div class="mb-4 text-center">
        <h1 class="font-display text-lg font-semibold">{{ $school->name }}</h1>
        <p class="text-sm font-semibold">{{ $exam->title }}</p>
        <p class="text-xs text-slate-500">
            {{ $exam->level?->name }} · {{ $exam->academicSession?->name }}
            @if ($exam->exam_date)
                · {{ $exam->exam_date->format('l, j F Y') }}
            @endif
            @if ($exam->starts_at)
                · {{ substr((string) $exam->starts_at, 0, 5) }}
            @endif
            @if ($exam->venue)
                · {{ $exam->venue }}
            @endif
        </p>
    </div>

    @if ($candidates->isEmpty())
        <div class="card-pad text-center">
            <p class="text-sm font-medium text-slate-900">No candidates registered on this examination yet</p>
            <p class="mt-1 text-sm text-slate-500">
                Register the candidate list first, then come back for the admit cards.
            </p>
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($candidates as $candidate)
                {{-- The sheet is cut up at the gate, so every card has to stand on its
                     own: the school's crest sits beside the candidate's details, the
                     way it does on a card the school had printed for it. --}}
                <article class="card flex items-stretch gap-4 p-4 print:break-inside-avoid print:rounded-lg">
                    @if ($candidate->photo_path)
                        <img src="{{ asset('storage/' . $candidate->photo_path) }}"
                             alt="Passport photograph of {{ $candidate->full_name }}"
                             class="h-24 w-20 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                    @else
                        <span class="inline-flex h-24 w-20 shrink-0 items-center justify-center rounded-lg bg-brand-900 font-display text-lg font-semibold text-gold-300">
                            {{ $candidate->initials }}
                        </span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">
                            Admit card
                        </p>
                        <p class="mt-0.5 truncate font-display text-base font-semibold text-slate-900">
                            {{ $candidate->full_name }}
                        </p>
                        <p class="font-mono text-xs text-slate-500">{{ $candidate->registration_number }}</p>

                        {{-- Labelled like a form rather than written out as a sentence:
                             it is read at a glance by whoever is on the gate. --}}
                        <dl class="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-1.5 text-slate-600">
                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Class</dt>
                                <dd class="text-[11px] font-medium">{{ $candidate->levelAppliedFor?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Date</dt>
                                <dd class="text-[11px] font-medium">{{ $exam->exam_date?->format('j M Y') ?? 'To be announced' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Time</dt>
                                <dd class="text-[11px] font-medium">{{ $exam->starts_at ? substr((string) $exam->starts_at, 0, 5) : 'To be announced' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Venue</dt>
                                <dd class="text-[11px] font-medium">{{ $exam->venue ?? 'School campus' }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- The school's own crest, which is what makes the card look like
                         the school issued it. Falls back to its monogram when no logo
                         has been uploaded. --}}
                    <div class="flex shrink-0 flex-col items-center justify-center border-l border-slate-200 pl-4">
                        <x-brand-mark size="xl" />
                    </div>
                </article>
            @endforeach
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            Candidates must bring this card and arrive at least thirty minutes before the paper begins.
        </p>
    @endif
</div>

</body>
</html>
