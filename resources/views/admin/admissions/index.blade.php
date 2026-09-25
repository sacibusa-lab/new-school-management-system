@extends('layouts.admin')

@section('title', 'Cutoff & admission decisions')
@section('subtitle', 'Set the pass mark, compute the merit list, and transfer successful applicants')

@section('content')

@if ($exams->isEmpty())
    <x-empty-state
        title="No examination to decide on"
        description="Create an examination and capture its scores first. The cutoff desk becomes available once you have marks."
        icon="scale">
        @can('exams.manage')
            <a href="{{ route('admin.exams.create') }}" class="btn-primary">Create an examination</a>
        @endcan
    </x-empty-state>
@else

{{-- ================= Pick the examination ================= --}}
<form method="GET" class="card-pad">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-field name="exam" label="Examination" type="select"
                     :value="$exam?->id"
                     :options="$exams->mapWithKeys(fn ($e) => [$e->id => $e->title . ' — ' . ($e->level?->name ?? 'All levels') . ' (' . $e->academicSession?->name . ')'])->all()" />
        </div>

        <x-field name="decision" label="Filter decisions" type="select"
                 placeholder-option="All decisions"
                 :value="request('decision')"
                 :options="$decisionOptions" />

        <div class="flex items-end">
            <button type="submit" class="btn-secondary w-full">Load</button>
        </div>
    </div>
</form>

@if ($exam && $statistics)

    {{-- ================= Statistics ================= --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <x-stat-card label="Computed" :value="$statistics['total']" icon="list" tone="slate" />
        <x-stat-card label="Admitted" :value="$statistics['admitted']" icon="academic" tone="emerald" />
        <x-stat-card label="Not admitted" :value="$statistics['rejected']" icon="users" tone="rose" />
        <x-stat-card label="Out of places" :value="$statistics['deferred']" icon="clock" tone="gold" />
        <x-stat-card label="Awaiting cutoff" :value="$statistics['pending']" icon="scale" tone="brand" />
        <x-stat-card label="Cutoff in force"
                     :value="rtrim(rtrim(number_format($statistics['cutoff'], 2), '0'), '.') . '%'"
                     :hint="$statistics['total'] > 0 ? 'Highest ' . $statistics['highest'] . '% · average ' . $statistics['average'] . '%' : 'No candidates yet'"
                     icon="chart" tone="brand" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">

        {{-- ================= Cutoff settings ================= --}}
        <div class="card">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Cutoff marks</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $session?->name }} · set by the exam officer
                    </p>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @php $activeLevels = $levels->where('id', $exam->level_id); @endphp

                @foreach (($activeLevels->isNotEmpty() ? $activeLevels : $levels) as $level)
                    @php $setting = $level->admissionSettings->first(); @endphp

                    <form method="POST" action="{{ route('admin.admissions.settings.update', $level) }}" class="p-5">
                        @csrf
                        @method('PUT')

                        <input type="hidden" name="academic_session_id" value="{{ $session?->id }}">

                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-900">{{ $level->name }}</p>
                            @if ($setting)
                                <span class="badge bg-brand-50 text-brand-700 ring-brand-600/20">
                                    {{ rtrim(rtrim(number_format((float) $setting->cutoff_mark, 2), '0'), '.') }}%
                                </span>
                            @else
                                <span class="badge bg-slate-100 text-slate-600 ring-slate-500/20">Not set</span>
                            @endif
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <x-field name="cutoff_mark" label="Cutoff %" type="number" required
                                     min="0" max="100" step="0.01"
                                     :value="$setting?->cutoff_mark ?? 50" />

                            <x-field name="subject_pass_mark" label="Subject pass %" type="number" required
                                     min="0" max="100" step="0.01"
                                     :value="$setting?->subject_pass_mark ?? 40" />

                            <x-field name="available_slots" label="Places" type="number" min="1"
                                     placeholder="Unlimited"
                                     :value="$setting?->available_slots" />

                            <div class="pt-6">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="require_all_subjects" value="1"
                                           @checked($setting?->require_all_subjects)
                                           class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                    Must pass all
                                </label>
                            </div>
                        </div>

                        @can('admissions.cutoff')
                            <button type="submit" class="btn-secondary btn-sm mt-4 w-full">Save {{ $level->name }}</button>
                        @endcan
                    </form>
                @endforeach
            </div>
        </div>

        {{-- ================= Run the pipeline ================= --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="card-pad">
                <h2 class="text-base font-semibold text-slate-900">Run the admission pipeline</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Work down the three steps in order. Each one is safe to run more than once.
                </p>

                <div class="mt-6 space-y-4">
                    {{-- Step 1 --}}
                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">1. Compute the merit list</p>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Totals each candidate's marks as a percentage and ranks them by class.
                            </p>
                        </div>

                        @can('admissions.decide')
                            <form method="POST" action="{{ route('admin.admissions.compute', $exam) }}">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm">Compute</button>
                            </form>
                        @endcan
                    </div>

                    {{-- Step 2 --}}
                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">2. Apply the cutoff mark</p>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Marks everyone above the cutoff as admitted, respecting the number of places.
                            </p>
                        </div>

                        @can('admissions.decide')
                            <form method="POST" action="{{ route('admin.admissions.apply', $exam) }}"
                                  onsubmit="return confirm('Apply the cutoff mark and set decisions for every candidate?')">
                                @csrf
                                <input type="hidden" name="respect_slots" value="1">
                                <button type="submit" class="btn-gold btn-sm">Apply cutoff</button>
                            </form>
                        @endcan
                    </div>

                    {{-- Step 3 --}}
                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
                        <div>
                            <p class="text-sm font-semibold text-emerald-900">3. Transfer admitted applicants</p>
                            <p class="mt-0.5 text-sm text-emerald-800">
                                Issues an admission number like
                                <span class="font-mono">SAC/{{ $session?->startYear() ?? now()->year }}/001</span>,
                                creates a portal login, and raises the first fee invoice.
                            </p>
                        </div>

                        @can('admissions.enrol')
                            <form method="POST" action="{{ route('admin.admissions.enrol', $exam) }}"
                                  onsubmit="return confirm('Transfer every admitted applicant into the results and fees portals?')">
                                @csrf
                                <button type="submit" class="btn-primary btn-sm">Transfer now</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <p class="mt-5 rounded-xl bg-slate-50 p-4 text-xs text-slate-600 ring-1 ring-slate-200">
                    An applicant is only ever transferred once. Running step 3 again simply skips
                    anyone who already has a student record.
                </p>
            </div>
        </div>
    </div>

    {{-- ================= Resit ================= --}}
    @can('admissions.resit')
        @if ($exam)
            <div class="card-pad mt-6" x-data="{ all: false }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="eyebrow">Another chance</p>
                        <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Resit examination</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">
                            A resit is a fresh sitting built from this one. Only the papers each candidate
                            actually failed are copied across, and it runs through the same score entry,
                            marking and cutoff steps — so nothing extra to learn.
                        </p>
                    </div>

                    @if (! $resitEnabled)
                        <span class="badge bg-slate-100 text-slate-600 ring-slate-500/20">Switched off in Settings</span>
                    @endif
                </div>

                {{-- Resits already created from this sitting. --}}
                @if ($existingResits->isNotEmpty())
                    <div class="mt-5 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Already created from this sitting
                        </p>

                        <ul class="mt-3 space-y-2">
                            @foreach ($existingResits as $resit)
                                <li class="flex flex-wrap items-center gap-3 text-sm">
                                    <x-status-pill :status="$resit->status" />
                                    <span class="font-medium text-slate-800">
                                        Resit {{ $resit->resit_round }}
                                    </span>
                                    <span class="text-slate-500">
                                        {{ $resit->examSubjects_count }} paper(s)
                                        @if ($resit->exam_date)
                                            · {{ $resit->exam_date->format('j M Y') }}
                                        @endif
                                    </span>
                                    <a href="{{ route('admin.exams.show', $resit) }}"
                                       class="ml-auto text-xs font-medium text-brand-700 hover:underline">
                                        Open sitting →
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($resitCandidates->isEmpty())
                    <p class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-slate-200">
                        Nobody is eligible for a resit yet. Candidates appear here once they have sat
                        {{ $exam->title }} and have not been admitted.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.admissions.resit', $exam) }}" class="mt-5">
                        @csrf

                        <div class="flex items-center justify-between gap-4">
                            <p class="text-sm font-medium text-slate-700">
                                {{ $resitCandidates->count() }} candidate(s) eligible
                            </p>

                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                <input type="checkbox" x-model="all"
                                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Select all
                            </label>
                        </div>

                        <div class="mt-3 max-h-72 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                            @foreach ($resitCandidates as $candidate)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg p-2 transition hover:bg-slate-50">
                                    <input type="checkbox" name="applicant_ids[]" value="{{ $candidate->id }}"
                                           x-bind:checked="all"
                                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">

                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-slate-900">
                                            {{ $candidate->full_name }}
                                        </span>
                                        <span class="block font-mono text-xs text-slate-500">
                                            {{ $candidate->registration_number }}
                                        </span>
                                    </span>

                                    <x-status-pill :status="$candidate->status" />
                                </label>
                            @endforeach
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <x-field name="exam_date" label="Resit date" type="date"
                                     hint="Leave blank to announce the date later." />
                            <x-field name="venue" label="Venue" placeholder="e.g. Main Hall" />
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button type="submit" class="btn-primary btn-sm" @disabled(! $resitEnabled)>
                                Create resit sitting
                            </button>
                            <p class="text-xs text-slate-500">
                                Guardians are texted automatically, and each candidate
                                keeps the marks they already passed.
                            </p>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    @endcan

    {{-- ================= Decisions ================= --}}
    <div class="mt-6">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-16 text-center">Rank</th>
                        <th>Applicant</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Offered</th>
                        <th class="text-center">Passed</th>
                        <th class="text-center">Failed</th>
                        <th class="text-right">Average</th>
                        <th class="text-right">Vs cutoff</th>
                        <th>Decision</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($decisions as $decision)
                        @php $margin = $decision->margin(); @endphp

                        <tr>
                            <td class="text-center font-semibold text-slate-900">{{ $decision->position ?? '—' }}</td>

                            <td>
                                <p class="font-medium text-slate-900">{{ $decision->applicant?->full_name }}</p>
                                <p class="font-mono text-xs text-slate-500">
                                    {{ $decision->applicant?->registration_number }}
                                    @if ($decision->applicant?->student)
                                        → {{ $decision->applicant->student->student_number }}
                                    @endif
                                </p>
                            </td>

                            <td class="text-center text-sm">{{ $decision->subjects_offered }}</td>
                            <td class="text-center text-sm">{{ $decision->subjects_offered }}</td>
                            <td class="text-center text-sm font-medium text-emerald-700">{{ $decision->subjects_passed }}</td>
                            <td class="text-center text-sm {{ $decision->subjects_failed > 0 ? 'font-medium text-rose-600' : 'text-slate-400' }}">
                                {{ $decision->subjects_failed }}
                            </td>

                            <td class="text-right font-semibold text-slate-900">
                                {{ rtrim(rtrim(number_format((float) $decision->average_score, 2), '0'), '.') }}%
                            </td>

                            <td class="text-right text-sm font-medium {{ $margin >= 0 ? 'text-emerald-700' : 'text-rose-600' }}">
                                {{ $margin >= 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($margin, 2), '0'), '.') }}
                            </td>

                            <td>
                                <x-status-pill :status="$decision->decision" />

                                {{-- Caught by a decision being flipped to Admitted after the
                                     transfer already ran: the applicant would otherwise sit in
                                     limbo with no admission number, login or invoice. --}}
                                @if ($decision->decision === \App\Enums\AdmissionDecisionStatus::Admitted
                                     && $decision->applicant
                                     && ! $decision->applicant->student)
                                    <p class="mt-1.5 text-[11px] font-semibold text-gold-700">
                                        Not transferred yet
                                    </p>
                                @endif
                            </td>

                            <td class="text-right">
                                @can('admissions.decide')
                                    @if ($decision->applicant && ! $decision->applicant->student)
                                        <form method="POST" action="{{ route('admin.admissions.decision.override', $decision) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf

                                            <select name="decision" class="input py-1.5 pl-2 pr-7 text-xs">
                                                @foreach ($decisionOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected($decision->decision->value === $value)>
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            <button type="submit" class="btn-ghost btn-sm">Set</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">Transferred</span>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-16 text-center">
                                <p class="text-sm font-medium text-slate-900">Nothing computed yet</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Run step 1 above to build the merit list from the captured scores.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">{{ $decisions->links() }}</div>
    </div>
@endif

@endif

@endsection
