@extends('layouts.admin')

@section('title', $exam->title)
@section('subtitle', ($exam->level?->name ?? 'All levels') . ' · ' . $exam->academicSession?->name)

@section('actions')
    @can('scores.enter')
        <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-secondary btn-sm">Type scores</a>
    @endcan
    @can('scores.import')
        <a href="{{ route('admin.imports.index', ['exam' => $exam->id]) }}" class="btn-primary btn-sm">Upload scoresheet</a>
    @endcan
@endsection

@section('content')

{{-- ================= Summary ================= --}}
<div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card label="Candidates" :value="number_format($candidateCount)" hint="Applicants sitting this paper" icon="users" tone="brand" />
    <x-stat-card label="Subjects" :value="$examSubjects->count()" :hint="'Total marks: ' . rtrim(rtrim(number_format($exam->totalMarks(), 2), '0'), '.')" icon="list" tone="gold" />
    <x-stat-card label="Status" :value="$exam->status->label()" :hint="$exam->results_locked ? 'Results are locked' : ($exam->isEditable() ? 'Still editable' : 'Editing closed')" icon="clipboard" tone="emerald" />
    <x-stat-card label="Cutoff" :value="rtrim(rtrim(number_format((float) ($exam->cutoff_mark ?? 50), 2), '0'), '.') . '%'"
                 hint="{{ $exam->exam_date?->format('j M Y') ?? 'No date set' }}" icon="scale" tone="rose" />
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    {{-- ================= Subjects & progress ================= --}}
    <div class="card lg:col-span-2">
        <div class="panel-header">
            <div>
                <p class="panel-title">Score capture by subject</p>
                <p class="mt-0.5 text-xs text-slate-500">How much of the marking has been captured</p>
            </div>
        </div>

        @if ($examSubjects->isEmpty())
            <div class="p-5 sm:p-6">
                <x-empty-state title="No subjects yet" description="Add the subjects being examined before registering candidates." icon="list" />
            </div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($progress as $row)
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900">{{ $row['examSubject']->subject?->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Out of {{ rtrim(rtrim(number_format((float) $row['examSubject']->total_marks, 2), '0'), '.') }}
                                · pass mark {{ rtrim(rtrim(number_format($row['examSubject']->effectivePassMark(), 2), '0'), '.') }}
                                · {{ $row['candidates'] }} candidate(s)
                            </p>
                        </div>

                        <div class="w-full sm:w-40">
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span>{{ $row['captured'] }}/{{ $row['candidates'] }}</span>
                                <span class="font-semibold text-slate-700">{{ $row['percent'] }}%</span>
                            </div>
                            <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div @class([
                                    'h-full rounded-full transition-all',
                                    'bg-emerald-500' => $row['percent'] === 100,
                                    'bg-brand-600' => $row['percent'] < 100,
                                ]) style="width: {{ $row['percent'] }}%"></div>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            @can('scores.enter')
                                <a href="{{ route('admin.scores.entry', [$exam, $row['examSubject']]) }}" class="btn-ghost btn-sm">Enter</a>
                            @endcan

                            @can('exams.manage')
                                <form method="POST" action="{{ route('admin.exams.subjects.destroy', [$exam, $row['examSubject']]) }}"
                                      onsubmit="return confirm('Remove this subject from the examination?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Remove</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @can('exams.manage')
            @if ($availableSubjects->isNotEmpty())
                <form method="POST" action="{{ route('admin.exams.subjects.store', $exam) }}"
                      class="border-t border-slate-200 bg-slate-50/70 p-5">
                    @csrf

                    <p class="text-sm font-semibold text-slate-900">Add a subject</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-4">
                        <div class="sm:col-span-2">
                            <x-field name="subject_id" label="Subject" type="select" required
                                     placeholder-option="Choose a subject"
                                     :options="$availableSubjects->pluck('name', 'id')->all()" />
                        </div>

                        <x-field name="total_marks" label="Total marks" type="number" required value="100" min="1" />
                        <x-field name="pass_mark" label="Pass mark" type="number" placeholder="40" min="0" />
                    </div>

                    <button type="submit" class="btn-secondary btn-sm mt-4">Add subject</button>
                </form>
            @endif
        @endcan
    </div>

    {{-- ================= Actions ================= --}}
    <aside class="space-y-6">
        <div class="card-pad">
            <h3 class="text-sm font-semibold text-slate-900">Candidates</h3>
            <p class="mt-2 text-sm text-slate-600">
                Registering candidates creates a blank score slot for every applicant in
                {{ $exam->level?->name ?? 'this class' }} across {{ $examSubjects->count() }} subject(s).
            </p>

            @can('exams.manage')
                <form method="POST" action="{{ route('admin.exams.candidates.sync', $exam) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn-secondary w-full">
                        {{ $candidateCount > 0 ? 'Re-sync candidate list' : 'Register candidates' }}
                    </button>
                </form>
            @endcan
        </div>

        <div class="card-pad">
            <h3 class="text-sm font-semibold text-slate-900">Examination details</h3>

            <dl class="mt-4 space-y-3 text-sm">
                @foreach ([
                    ['Date', $exam->exam_date?->format('l, j F Y') ?? 'Not scheduled'],
                    ['Start time', $exam->starts_at ? \Illuminate\Support\Str::of($exam->starts_at)->substr(0, 5) : '—'],
                    ['Venue', $exam->venue ?? '—'],
                    ['Created by', $exam->creator?->name ?? '—'],
                    ['Instructions', $exam->instructions ?: 'None'],
                ] as [$label, $value])
                    <div class="flex justify-between gap-4 border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                        <dt class="shrink-0 text-slate-500">{{ $label }}</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @can('exams.manage')
                <a href="{{ route('admin.exams.edit', $exam) }}" class="btn-secondary btn-sm mt-5 w-full">Edit examination</a>
            @endcan
        </div>

        @if ($imports->isNotEmpty())
            <div class="card">
                <div class="panel-header">
                    <p class="panel-title">Recent uploads</p>
                    <a href="{{ route('admin.imports.index', ['exam' => $exam->id]) }}" class="text-xs font-semibold text-brand-700">All</a>
                </div>

                <div class="divide-y divide-slate-100">
                    @foreach ($imports as $import)
                        <a href="{{ route('admin.imports.show', $import) }}" class="block px-5 py-3.5 transition hover:bg-slate-50">
                            <div class="flex items-start justify-between gap-3">
                                <p class="truncate text-sm text-slate-800">{{ $import->original_name }}</p>
                                <x-status-pill :status="$import->status" />
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $import->rows_total }} rows · {{ $import->driver->label() }}
                            </p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </aside>
</div>

{{-- ================= Candidate list ================= --}}
@if ($candidates->isNotEmpty())
    <div class="card mt-6">
        <div class="panel-header">
            <div>
                <p class="panel-title">Candidates sitting this examination</p>
                <p class="mt-0.5 text-xs text-slate-500">
                    Use this to see whose marks are still outstanding before you apply the cutoff
                </p>
            </div>

            <span class="badge bg-brand-50 text-brand-700 ring-brand-600/20">
                {{ $candidates->count() }} candidate(s)
            </span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-12 text-center">#</th>
                        <th>Registration no.</th>
                        <th>Candidate</th>
                        <th>Class</th>
                        <th>Contact</th>
                        <th class="w-40">Marks captured</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($candidates as $index => $row)
                        <tr>
                            <td class="text-center text-xs text-slate-400">{{ $index + 1 }}</td>

                            <td class="font-mono text-xs font-medium text-slate-900">
                                {{ $row['applicant']->registration_number }}
                            </td>

                            <td>
                                <p class="font-medium text-slate-900">{{ $row['applicant']->full_name }}</p>
                                <x-status-pill :status="$row['applicant']->status" class="mt-1" />
                            </td>

                            <td class="text-sm">{{ $row['applicant']->levelAppliedFor?->name ?? '—' }}</td>

                            <td class="text-sm">
                                <p class="text-slate-700">
                                    {{ $row['applicant']->guardian_phone ?? $row['applicant']->phone ?? '—' }}
                                </p>
                            </td>

                            <td>
                                <div class="flex items-center justify-between text-xs text-slate-500">
                                    <span>{{ $row['marked'] }}/{{ $row['total'] }}</span>
                                    <span class="font-semibold {{ $row['percent'] === 100 ? 'text-emerald-700' : 'text-amber-700' }}">
                                        {{ $row['percent'] === 100 ? 'Complete' : 'Outstanding' }}
                                    </span>
                                </div>

                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div @class([
                                        'h-full rounded-full',
                                        'bg-emerald-500' => $row['percent'] === 100,
                                        'bg-amber-500' => $row['percent'] < 100,
                                    ]) style="width: {{ $row['percent'] }}%"></div>
                                </div>
                            </td>

                            <td class="text-right">
                                <a href="{{ route('admin.applicants.show', $row['applicant']) }}"
                                   class="btn-ghost btn-sm">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@elseif ($examSubjects->isNotEmpty())
    <div class="card-pad mt-6">
        <p class="text-sm font-medium text-slate-900">No candidates registered yet</p>
        <p class="mt-1 text-sm text-slate-500">
            Register the applicants for this class using the button above — that creates a blank mark
            for every candidate against every subject.
        </p>
    </div>
@endif

@endsection
