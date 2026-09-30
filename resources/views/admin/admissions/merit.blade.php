@php
    // A standalone sheet: no sidebar, no toolbar. What prints is the merit list and
    // nothing else, which is the only way a school actually uses it.
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merit list — {{ $exam->title }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 print:bg-white">

<div class="mx-auto max-w-5xl p-6 print:max-w-none print:p-0">

    <div class="no-print mb-5 flex flex-wrap items-center gap-3">
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">Print this list</button>
        <a href="{{ route('admin.admissions.merit.csv', $exam) }}" class="btn-secondary btn-sm">Download as Excel (CSV)</a>
        <a href="{{ route('admin.admissions.index', ['exam' => $exam->id]) }}" class="btn-ghost btn-sm">&larr; Cutoff &amp; decisions</a>
    </div>

    <div class="card p-6 print:border-0 print:p-0 print:shadow-none">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
            @if ($school->letterhead)
                {{-- Head paper: the school's own letterhead, across the whole width of
                     the sheet and nothing beside it. The sheet's own title drops to the
                     line underneath, the way a headed document reads. --}}
                <img src="{{ asset('storage/' . $school->letterhead) }}"
                     alt="{{ $school->name }}"
                     class="mx-auto block w-full">
            @else
                <div>
                    <h1 class="font-display text-xl font-semibold">{{ $school->name }}</h1>
                    @if ($school->address)
                        <p class="text-xs text-slate-500">{{ $school->address }}</p>
                    @endif
                    @if ($school->phone)
                        <p class="text-xs text-slate-500">{{ $school->phone }}</p>
                    @endif
                </div>
            @endif

            <div @class([
                'text-right' => ! $school->letterhead,
                'mx-auto text-center' => (bool) $school->letterhead,
            ])>
                <p class="eyebrow">Merit list</p>
                <p class="mt-2 text-sm font-semibold">{{ $exam->title }}</p>
                <p class="text-xs text-slate-500">
                    {{ $exam->level?->name }} · {{ $exam->academicSession?->name }}
                </p>
            </div>
        </header>

        <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-xs">
            <div>
                <dt class="inline text-slate-500">Cutoff mark</dt>
                <dd class="inline font-semibold">{{ $fmt($cutoff) }}%</dd>
            </div>
            <div>
                <dt class="inline text-slate-500">Candidates ranked</dt>
                <dd class="inline font-semibold">{{ $decisions->count() }}</dd>
            </div>
            <div>
                <dt class="inline text-slate-500">Admitted</dt>
                <dd class="inline font-semibold">{{ $admitted }}</dd>
            </div>
            <div>
                <dt class="inline text-slate-500">Places</dt>
                <dd class="inline font-semibold">
                    {{ $slots === null ? 'No limit set' : $slots . ($free !== null ? " ({$free} free)" : '') }}
                </dd>
            </div>
            <div>
                <dt class="inline text-slate-500">Published</dt>
                <dd class="inline font-semibold">{{ now()->format('j F Y') }}</dd>
            </div>
        </dl>

        @if ($decisions->isEmpty())
            <p class="py-12 text-center text-sm text-slate-500">
                The merit list has not been computed for this examination yet.
            </p>
        @else
            <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-slate-200 print:rounded-none print:ring-0">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th rowspan="2" class="px-3 py-2 text-left align-bottom text-xs font-semibold uppercase tracking-wider text-slate-500">Pos</th>
                            <th rowspan="2" class="px-3 py-2 text-left align-bottom text-xs font-semibold uppercase tracking-wider text-slate-500">Candidate</th>

                            {{-- One column per paper rather than a count of them: this is
                                 the sheet the office defends a decision with, and "2/2"
                                 answers nobody who asks what the child actually scored. --}}
                            @if ($papers->isNotEmpty())
                                <th colspan="{{ $papers->count() }}"
                                    class="border-l border-slate-200 px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    Subjects
                                </th>
                            @endif

                            <th rowspan="2" class="px-3 py-2 text-right align-bottom text-xs font-semibold uppercase tracking-wider text-slate-500">Total</th>
                            <th rowspan="2" class="px-3 py-2 text-right align-bottom text-xs font-semibold uppercase tracking-wider text-slate-500">Average</th>
                            <th rowspan="2" class="px-3 py-2 text-right align-bottom text-xs font-semibold uppercase tracking-wider text-slate-500">Decision</th>
                        </tr>

                        @if ($papers->isNotEmpty())
                            <tr>
                                @foreach ($papers as $paper)
                                    <th class="border-l border-slate-200 px-3 py-2 text-right text-[11px] font-semibold text-slate-500">
                                        {{ $paper->subject?->code ?? $paper->label() }}
                                    </th>
                                @endforeach
                            </tr>
                        @endif
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($decisions as $decision)
                            @php
                                // The candidate's own marks, keyed by paper, so each
                                // subject cell is a lookup rather than a query.
                                $rows = $marks->get($decision->applicant_id, collect());
                            @endphp
                            <tr>
                                <td class="px-3 py-2 align-top font-mono text-xs text-slate-500">{{ $decision->position ?? '—' }}</td>
                                <td class="px-3 py-2 align-top">
                                    <p class="font-medium text-slate-900">
                                        {{ $decision->applicant?->full_name ?? '—' }}
                                    </p>
                                    {{-- Under the name: on a sheet read down a name column the
                                         number belongs with the person, not in a column of its own. --}}
                                    <p class="font-mono text-[11px] text-slate-500">
                                        {{ $decision->applicant?->registration_number ?? '—' }}
                                    </p>
                                </td>

                                @foreach ($papers as $paper)
                                    @php $mark = $rows->get($paper->id); @endphp
                                    <td class="border-l border-slate-100 px-3 py-2 text-right align-top">
                                        @if ($mark === null || (! $mark['is_absent'] && $mark['percentage'] === null))
                                            {{-- A paper nobody recorded a mark for is not a zero. --}}
                                            <span class="text-slate-300">—</span>
                                        @elseif ($mark['is_absent'])
                                            <span class="text-rose-600">Absent</span>
                                        @else
                                            {{ $fmt($mark['percentage']) }}%
                                        @endif
                                    </td>
                                @endforeach

                                <td class="px-3 py-2 text-right align-top text-slate-600">{{ $fmt($decision->total_score) }}</td>
                                <td class="px-3 py-2 text-right align-top font-semibold">{{ $fmt($decision->average_score) }}%</td>
                                <td class="px-3 py-2 text-right align-top">
                                    @php $verdict = $decision->decision; @endphp
                                    <span @class([
                                        'font-semibold',
                                        'text-emerald-700' => $verdict === \App\Enums\AdmissionDecisionStatus::Admitted,
                                        'text-rose-600' => $verdict === \App\Enums\AdmissionDecisionStatus::Rejected,
                                        'text-sky-700' => $verdict === \App\Enums\AdmissionDecisionStatus::Deferred,
                                        'text-slate-500' => $verdict === null || $verdict === \App\Enums\AdmissionDecisionStatus::Pending,
                                        'text-slate-400' => $verdict === \App\Enums\AdmissionDecisionStatus::Withdrawn,
                                    ])>{{ $verdict?->label() ?? 'Not decided' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="mt-4 text-xs text-slate-500">
            Each subject column is the candidate's mark in that paper as a percentage of it, so a
            paper marked out of fifty counts the same as one out of a hundred. The cutoff for this
            examination is {{ $fmt($cutoff) }}% of each candidate's average across the papers sat.
            Anyone can check a candidate's own marks on their admission status page.
        </p>
    </div>
</div>

</body>
</html>
