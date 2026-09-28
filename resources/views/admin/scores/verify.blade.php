@extends('layouts.admin')

@section('title', 'Verify marks — ' . $exam->title)
@section('subtitle', 'Sign off marks that were read off a sheet by machine')

@section('actions')
    <a href="{{ route('admin.scores.grid', $exam) }}" class="btn-secondary btn-sm">Open the grid</a>
    <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-secondary btn-sm">&larr; Subjects</a>
@endsection

@section('content')

@php
    $trusted = fn ($score) => $score->confidence === null || (float) $score->confidence >= 0.85;
    $rowIndex = 0;
@endphp

<div class="grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Awaiting verification" :value="$awaiting->count()" icon="shield" tone="gold"
                 hint="Read by machine, not yet signed off" />
    <x-stat-card label="Papers waiting" :value="$awaitingBySubject->count()" icon="list" tone="brand" />
    <x-stat-card label="Marks on this examination" :value="$totalScores" icon="clipboard" tone="slate" />
</div>

@if ($awaiting->isEmpty() && $verifiedBySubject->isEmpty())
    <div class="mt-6">
        <x-empty-state
            title="Nothing is waiting to be verified"
            description="Every mark that a machine read on this examination has been signed off. Marks typed by hand and uploaded from a spreadsheet never need this step, because a person already entered them."
            icon="shield">
            <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-primary">
                Back to score entry
            </a>
        </x-empty-state>
    </div>
@else
    @if ($awaiting->isEmpty())
        <div class="mt-6">
            <x-alert tone="success" title="Everything is signed off">
                No mark on this examination is still waiting to be verified. The papers below show
                what has been signed off, in case one of those signatures turns out to be wrong.
            </x-alert>
        </div>
    @endif

    @if ($awaiting->isNotEmpty())
    <div class="mt-6 rounded-xl bg-gold-50 px-5 py-4 ring-1 ring-gold-600/20">
        <h3 class="text-sm font-semibold text-gold-900">Why these marks are held back</h3>
        <p class="mt-1.5 text-sm text-gold-900">
            A sheet that was photographed or scanned is read by software, and software misreads
            handwriting. Nothing here counts towards the merit list until a person compares it with
            the script and signs it off.
        </p>
        <ul class="mt-3 space-y-1 text-sm text-gold-900">
            <li>· Boxes already ticked are the reads the software was confident about.</li>
            <li>· Unticked boxes are the shaky ones — those are the ones worth a second look.</li>
            <li>· Type into any box to correct it. The box also takes <strong>A</strong> for absent.</li>
        </ul>
    </div>
    @endif

    @unless ($editable)
        <div class="mt-6 rounded-xl bg-amber-50 px-5 py-4 text-sm text-amber-900 ring-1 ring-amber-600/20">
            This examination is locked. The marks are shown for checking, but nothing can be saved.
        </div>
    @endunless

    @if ($errors->any())
        <div class="mt-6 rounded-xl bg-rose-50 px-5 py-4 ring-1 ring-rose-600/20">
            <h3 class="text-sm font-semibold text-rose-900">Nothing was saved</h3>
            <p class="mt-1 text-sm text-rose-800">
                Fix the marks below and save again. A sheet is written as a whole, so one bad box
                holds up the rest.
            </p>
            <ul class="mt-2 list-inside list-disc text-sm text-rose-800">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 space-y-6">
        @foreach ($papers as $subjectId => $examSubject)
            @php
                $rows = $awaitingBySubject[$subjectId] ?? collect();
                $verified = $verifiedBySubject[$subjectId] ?? collect();
                $max = (float) ($examSubject->total_marks ?? 100);
                $pass = (float) ($examSubject->effectivePassMark() ?? 0);
                $shaky = $rows->reject($trusted)->count();
            @endphp

            <form method="POST" action="{{ route('admin.scores.verify.store', $exam) }}"
                  class="card overflow-hidden"
                  x-data="scoreGrid(@js($gradeScale->map(fn ($g) => ['min' => (float) $g->min_score, 'grade' => $g->grade])->values()))"
                  @input="onInput($event)"
                  @paste="onPaste($event)"
                  @keydown="onKeydown($event)">
                @csrf
                <input type="hidden" name="exam_subject_id" value="{{ $subjectId }}">

                <div class="panel-header">
                    <div>
                        <p class="panel-title">{{ $examSubject->subject?->name ?? 'Subject' }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Out of {{ rtrim(rtrim(number_format($max, 2), '0'), '.') }}
                            · pass mark {{ rtrim(rtrim(number_format($pass, 2), '0'), '.') }}%
                            @if ($rows->isEmpty())
                                · <span class="font-medium text-emerald-700">all signed off</span>
                            @elseif ($shaky > 0)
                                · <span class="font-medium text-amber-700">{{ $shaky }} uncertain read(s)</span>
                            @endif
                        </p>
                    </div>

                    @if ($rows->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn-primary btn-sm" @disabled(! $editable)>
                            Save &amp; verify ticked
                        </button>

                        <button type="submit" name="verify_all" value="1" class="btn-secondary btn-sm"
                                @disabled(! $editable)
                                onclick="return confirm('Verify every mark waiting on {{ $examSubject->subject?->name }}? Corrections you have typed will be saved first.')">
                            Verify all {{ $rows->count() }}
                        </button>
                    </div>
                    @endif
                </div>

                @if ($rows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="table score-grid">
                        <thead>
                            <tr>
                                <th class="w-12 text-center">#</th>
                                <th>Registration no.</th>
                                <th>Candidate</th>
                                <th class="w-40">Mark read</th>
                                <th>Read by</th>
                                <th class="w-24 text-center">Accept</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $score)
                                @php
                                    $rowIndex++;
                                    $cellError = $errors->has('scores.' . $score->id);
                                @endphp

                                <tr data-row="{{ $rowIndex }}"
                                    data-match="{{ strtolower($score->applicant?->full_name . ' ' . $score->applicant?->registration_number) }}">
                                    <td class="text-center text-xs text-slate-400">{{ $loop->iteration }}</td>

                                    <td class="font-mono text-xs font-medium text-slate-900">
                                        {{ $score->applicant?->registration_number }}
                                    </td>

                                    <td>
                                        <p class="font-medium text-slate-900">{{ $score->applicant?->full_name }}</p>
                                        @if ($score->applicant?->levelAppliedFor)
                                            <p class="text-xs text-slate-500">{{ $score->applicant->levelAppliedFor->name }}</p>
                                        @endif
                                    </td>

                                    <td class="align-top pb-1">
                                        <input type="text"
                                               inputmode="decimal"
                                               autocomplete="off"
                                               name="scores[{{ $score->id }}]"
                                               value="{{ old('scores.' . $score->id, $score->is_absent ? 'A' : $score->score) }}"
                                               data-row="{{ $rowIndex }}"
                                               data-column="1"
                                               data-max="{{ $max }}"
                                               data-pass="{{ $pass }}"
                                               @readonly(! $editable)
                                               @class([
                                                   'input py-2 text-center text-sm',
                                                   'input-error' => $cellError,
                                                   'ring-2 ring-amber-400' => ! $cellError && ! $trusted($score),
                                                   'bg-slate-50 text-slate-500' => ! $editable,
                                               ])>

                                        <span data-feedback class="mt-1 block text-center text-[11px] font-semibold"></span>

                                        @error('scores.' . $score->id)
                                            <p class="error-text text-center">{{ $message }}</p>
                                        @enderror
                                    </td>

                                    <td class="align-top">
                                        <span class="badge {{ $score->source->badge() }}">{{ $score->source->label() }}</span>

                                        @if ($score->confidence !== null)
                                            <p class="mt-1 text-[11px] text-slate-500">
                                                {{ (int) round((float) $score->confidence * 100) }}% sure
                                            </p>
                                        @endif

                                        @if ($score->enteredBy)
                                            <p class="text-[11px] text-slate-400">
                                                committed by {{ $score->enteredBy->name }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="text-center align-top">
                                        <input type="checkbox"
                                               name="verify[{{ $score->id }}]"
                                               value="1"
                                               @checked(old('verify.' . $score->id, $trusted($score)))
                                               @disabled(! $editable)
                                               class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                @if ($verified->isNotEmpty())
                    {{-- A verification is only ever wrong in one direction: a read was
                         accepted too quickly. Sending it back puts it in the queue
                         again instead of re-typing it under a signature that no
                         longer means anything. --}}
                    <details class="border-t border-slate-200 bg-slate-50/70">
                        <summary class="cursor-pointer px-5 py-3 text-xs font-medium text-slate-600 hover:text-slate-900">
                            {{ $verified->count() }} mark(s) on this paper already verified
                        </summary>

                        <ul class="divide-y divide-slate-200 border-t border-slate-200">
                            @foreach ($verified as $score)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5">
                                    <div class="min-w-0">
                                        <p class="text-sm text-slate-800">
                                            <span class="font-mono text-xs text-slate-500">{{ $score->applicant?->registration_number }}</span>
                                            {{ $score->applicant?->full_name }}
                                            <span class="font-semibold">
                                                {{ $score->is_absent ? 'absent' : rtrim(rtrim(number_format((float) $score->score, 2), '0'), '.') }}
                                            </span>
                                        </p>
                                        <p class="text-[11px] text-slate-500">
                                            {{ $score->source->label() }}
                                            @if ($score->verifiedBy) · signed off by {{ $score->verifiedBy->name }} @endif
                                        </p>
                                    </div>

                                    <label class="flex items-center gap-2 text-xs text-slate-600">
                                        <input type="checkbox"
                                               name="unverify[{{ $score->id }}]"
                                               value="1"
                                               @disabled(! $editable)
                                               class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                        Send back
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </form>
        @endforeach
    </div>
@endif

@endsection

@include('admin.scores.partials.grid-scripts')
