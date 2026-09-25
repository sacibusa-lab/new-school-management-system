@extends('layouts.admin')

@section('title', 'Enter scores — ' . ($examSubject->subject?->name ?? 'Subject'))
@section('subtitle', $exam->title . ' · out of ' . rtrim(rtrim(number_format((float) $examSubject->total_marks, 2), '0'), '.'))

@section('actions')
    <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-secondary btn-sm">&larr; All subjects</a>
@endsection

@section('content')

@php
    $max = (float) $examSubject->total_marks;
    $captured = $scores->whereNotNull('score')->count();
    $absent = $scores->where('is_absent', true)->count();
@endphp

{{-- ================= Summary ================= --}}
<div class="grid gap-4 sm:grid-cols-4">
    <x-stat-card label="Candidates" :value="$scores->count()" icon="users" tone="brand" />
    <x-stat-card label="Scores captured" :value="$captured" tone="emerald" icon="pencil"
                 :hint="$scores->count() > 0 ? (int) round(($captured / $scores->count()) * 100) . '% complete' : 'No candidates'" />
    <x-stat-card label="Marked absent" :value="$absent" icon="clipboard" tone="rose" />
    <x-stat-card label="Unmarked" :value="$scores->count() - $captured - $absent" icon="clock" tone="gold" />
</div>

@if ($scores->isEmpty())
    <div class="mt-6">
        <x-empty-state
            title="No candidates registered"
            description="Register the candidates for this examination first — that creates a score slot for each of them."
            icon="users">
            @can('exams.manage')
                <form method="POST" action="{{ route('admin.exams.candidates.sync', $exam) }}">
                    @csrf
                    <button type="submit" class="btn-primary">Register candidates now</button>
                </form>
            @endcan
        </x-empty-state>
    </div>
@else
    <form method="POST" action="{{ route('admin.scores.store', [$exam, $examSubject]) }}" class="mt-6">
        @csrf

        <div class="card overflow-hidden">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Marked scripts</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Enter each candidate's mark out of {{ rtrim(rtrim(number_format($max, 2), '0'), '.') }}.
                        Leave blank if the script has not been marked yet.
                    </p>
                </div>

                <button type="submit" class="btn-primary">Save all scores</button>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-12 text-center">#</th>
                            <th>Registration no.</th>
                            <th>Candidate</th>
                            <th class="w-36">Score</th>
                            <th class="w-24 text-center">Absent</th>
                            <th class="w-32">Source</th>
                            <th class="w-28">Verified</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($scores as $index => $score)
                            <tr>
                                <td class="text-center text-xs text-slate-400">{{ $index + 1 }}</td>

                                <td class="font-mono text-xs font-medium text-slate-900">
                                    {{ $score->applicant?->registration_number }}
                                </td>

                                <td>
                                    <p class="font-medium text-slate-900">{{ $score->applicant?->full_name }}</p>
                                    @if ($score->applicant?->levelAppliedFor)
                                        <p class="text-xs text-slate-500">{{ $score->applicant->levelAppliedFor->name }}</p>
                                    @endif
                                </td>

                                <td>
                                    <div class="relative">
                                        <input type="number"
                                               name="scores[{{ $score->id }}]"
                                               value="{{ old('scores.' . $score->id, $score->score) }}"
                                               min="0"
                                               max="{{ $max }}"
                                               step="0.01"
                                               @disabled($score->is_absent)
                                               class="input py-2 pr-12 text-sm"
                                               placeholder="—">

                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-400">
                                            /{{ rtrim(rtrim(number_format($max, 0), '0'), '.') }}
                                        </span>
                                    </div>

                                    @error('scores.' . $score->id)
                                        <p class="error-text">{{ $message }}</p>
                                    @enderror
                                </td>

                                <td class="text-center">
                                    <input type="checkbox"
                                           name="absent[{{ $score->id }}]"
                                           value="1"
                                           @checked(old('absent.' . $score->id, $score->is_absent))
                                           class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                </td>

                                <td>
                                    @if ($score->score !== null || $score->is_absent)
                                        <span class="badge {{ $score->source->badge() }}">{{ $score->source->label() }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Not captured</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($score->isVerified())
                                        <span class="flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                            </svg>
                                            Verified
                                        </span>
                                    @elseif ($score->needsVerification())
                                        <span class="badge bg-gold-50 text-gold-700 ring-gold-600/20">Check</span>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/70 px-5 py-4">
                <p class="text-xs text-slate-500">
                    Saving marks a candidate as having sat the paper and updates the examination status to
                    <strong>Marking</strong>.
                </p>

                <button type="submit" class="btn-primary">Save all scores</button>
            </div>
        </div>
    </form>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="card-pad">
            <h3 class="text-sm font-semibold text-slate-900">Reading the marks</h3>
            <p class="mt-2 text-sm text-slate-600">
                Scores are saved as a percentage of this subject's total for the merit list, so
                subjects can carry different total marks and still be compared fairly.
            </p>
        </div>

        <div class="card-pad">
            <h3 class="text-sm font-semibold text-slate-900">Grade boundaries</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($gradeScale as $grade)
                    <span class="badge bg-slate-100 text-slate-700 ring-slate-500/20">
                        {{ $grade->grade }}
                        <span class="font-normal text-slate-500">
                            {{ rtrim(rtrim(number_format((float) $grade->min_score, 0), '0'), '.') }}–{{ rtrim(rtrim(number_format((float) $grade->max_score, 0), '0'), '.') }}
                        </span>
                    </span>
                @endforeach
            </div>
        </div>
    </div>
@endif

@endsection
