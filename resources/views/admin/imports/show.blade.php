@extends('layouts.admin')

@section('title', 'Review: ' . $import->original_name)
@section('subtitle', $import->exam?->title . ' · ' . $driverLabel)

@section('actions')
    <a href="{{ route('admin.imports.index') }}" class="btn-secondary btn-sm">&larr; All imports</a>

    @if ($import->status !== \App\Enums\ScoreImportStatus::Committed)
        <form method="POST" action="{{ route('admin.imports.process', $import) }}">
            @csrf
            <button type="submit" class="btn-secondary btn-sm">Re-read file</button>
        </form>
    @endif
@endsection

@section('content')

{{-- ================= Outcome summary ================= --}}
<div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
    <x-stat-card label="Rows read" :value="$summary['total']" icon="list" tone="slate" />
    <x-stat-card label="Ready to save" :value="$summary['ready']" icon="pencil" tone="emerald" />
    <x-stat-card label="Need a decision" :value="$summary['ambiguous']" icon="scale" tone="gold" />
    <x-stat-card label="No student found" :value="$summary['unmatched']" icon="users" tone="rose" />
    <x-stat-card label="Already scored" :value="$summary['duplicate']" icon="clipboard" tone="gold" />
    <x-stat-card label="Saved" :value="$summary['committed']" icon="cash" tone="emerald" />
</div>

{{-- ================= What the machine read ================= --}}
<div class="mt-6 card-pad">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-600/10">
                <x-nav-icon name="upload" />
            </span>
            <div>
                <p class="text-sm font-semibold text-slate-900">{{ $driverLabel }}</p>
                <p class="mt-0.5 text-sm text-slate-600">
                    {{ $import->meta['description'] ?? 'Read from the uploaded file.' }}
                </p>
            </div>
        </div>

        <x-status-pill :status="$import->status" />
    </div>

    @php $unread = $import->meta['unread_headings'] ?? []; @endphp

    @if ($unread !== [])
        {{-- A column the school filled in that we could not place. Naming it is
             the only way anybody finds out those marks were not read. --}}
        <div class="mt-5">
            <x-alert tone="warning" title="Some columns were not read">
                These headings were neither a student column nor a paper on this examination, so
                nothing under them was read:
                <span class="font-medium">{{ implode(', ', $unread) }}</span>.
                If one of them is a paper, add the paper to the examination and upload the sheet
                again; otherwise the marks under it have to be typed in by hand.
            </x-alert>
        </div>
    @endif

    @if ($import->error)
        <div class="mt-5">
            <x-alert tone="danger" title="This file could not be read">{{ $import->error }}</x-alert>
        </div>
    @endif

    @if ($import->status === \App\Enums\ScoreImportStatus::Committed)
        <div class="mt-5">
            <x-alert tone="success" title="Committed">
                {{ $summary['committed'] }} score(s) were written to the examination
                @if ($import->committer)
                    by {{ $import->committer->name }}
                @endif
                @if ($import->committed_at)
                    on {{ $import->committed_at->format('j M Y \a\t g:ia') }}
                @endif
                .
            </x-alert>
        </div>

        @if ($import->driver->isImageBased())
            {{-- Read off a photograph, so nobody has agreed with these numbers yet. --}}
            <div class="mt-3">
                <x-alert tone="warning" title="These marks still need verifying">
                    They were read from an image by {{ strtolower($driverLabel) }}, which misreads
                    handwriting. Nothing here counts towards the merit list until a person compares
                    the marks with the scripts and signs them off.

                    @can('scores.verify')
                        <a href="{{ route('admin.scores.verify', $import->exam) }}" class="font-semibold underline">
                            Verify these marks
                        </a>
                    @endcan
                </x-alert>
            </div>
        @endif
    @endif
</div>

{{-- ================= Review grid ================= --}}
<div class="mt-6">
    @if ($rows->isEmpty())
        <x-empty-state title="Nothing was read from this file"
                       description="Check that the sheet has a header row naming the student and the score, then try again."
                       icon="upload" />
    @else
        <div class="card overflow-hidden">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Review each row before it counts</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Fix or skip anything the reader was unsure about. Only rows marked
                        <strong>Matched</strong> are written when you commit.
                    </p>
                </div>

                @if ($import->status !== \App\Enums\ScoreImportStatus::Committed)
                    <form method="POST" action="{{ route('admin.imports.commit', $import) }}"
                          onsubmit="return confirm('Save the {{ $summary['ready'] + $summary['ambiguous'] }} accepted row(s) to the examination?')">
                        @csrf
                        <button type="submit" class="btn-primary"
                                @disabled($summary['ready'] + $summary['ambiguous'] === 0)>
                            Commit {{ $summary['ready'] + $summary['ambiguous'] }} score(s)
                        </button>
                    </form>
                @endif
            </div>

            <div class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <div @class([
                        'p-5 transition',
                        'bg-rose-50/40' => $row->status === \App\Enums\ScoreImportRowStatus::Unmatched,
                        'bg-gold-50/40' => in_array($row->status, [\App\Enums\ScoreImportRowStatus::Ambiguous, \App\Enums\ScoreImportRowStatus::Duplicate, \App\Enums\ScoreImportRowStatus::Invalid], true),
                        'bg-slate-50/50' => in_array($row->status, [\App\Enums\ScoreImportRowStatus::Ignored, \App\Enums\ScoreImportRowStatus::Committed], true),
                    ])>
                        <div class="flex flex-wrap items-start gap-4">

                            {{-- Row number + status --}}
                            <div class="flex w-16 shrink-0 flex-col items-start gap-2">
                                <span class="text-xs font-semibold text-slate-400">Row {{ $row->row_number }}</span>
                                <x-status-pill :status="$row->status" />
                                @if ($row->confidencePercent() !== null)
                                    <span class="text-[11px] text-slate-500">
                                        {{ $row->confidencePercent() }}% sure
                                    </span>
                                @endif
                            </div>

                            {{-- What was read --}}
                            <div class="min-w-[15rem] flex-1">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Read from the sheet</p>

                                <div class="mt-2 space-y-1 text-sm">
                                    <p class="font-mono text-xs text-slate-700">
                                        {{ $row->raw_identifier ?: 'no number' }}
                                    </p>
                                    <p class="font-medium text-slate-900">{{ $row->raw_name ?: '— no name —' }}</p>
                                    <p class="text-slate-600">
                                        Score:
                                        <span class="font-semibold text-slate-900">
                                            {{ $row->raw_score !== null ? rtrim(rtrim(number_format((float) $row->raw_score, 2), '0'), '.') : 'blank' }}
                                        </span>
                                        @if ($row->raw_subject)
                                            · {{ $row->raw_subject }}
                                        @endif
                                    </p>
                                </div>

                                @if ($row->message)
                                    <p class="mt-2 text-xs text-slate-600">{{ $row->message }}</p>
                                @endif
                            </div>

                            {{-- Arrow --}}
                            <div class="hidden items-center self-center pt-4 lg:flex">
                                <svg class="h-5 w-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                                </svg>
                            </div>

                            {{-- Resolved to --}}
                            <div class="min-w-[15rem] flex-1">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Matched to</p>

                                @if ($row->status === \App\Enums\ScoreImportRowStatus::Committed)
                                    <div class="mt-2">
                                        <p class="text-sm font-semibold text-slate-900">{{ $row->matchedApplicant?->full_name }}</p>
                                        <p class="font-mono text-xs text-slate-500">{{ $row->matchedApplicant?->registration_number }}</p>
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('admin.imports.rows.resolve', [$import, $row]) }}" class="mt-2 space-y-3">
                                        @csrf

                                        <select name="matched_applicant_id" class="input py-2 text-sm">
                                            <option value="">— leave unassigned —</option>
                                            @foreach ($candidates as $candidate)
                                                <option value="{{ $candidate->id }}" @selected($row->matched_applicant_id === $candidate->id)>
                                                    {{ $candidate->registration_number }} · {{ $candidate->full_name }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <div class="flex gap-2">
                                            <input type="number" name="raw_score" step="0.01" min="0"
                                                   value="{{ $row->raw_score }}"
                                                   placeholder="Score"
                                                   class="input py-2 text-sm">

                                            <select name="exam_subject_id" class="input py-2 text-sm">
                                                <option value="">Subject…</option>
                                                @foreach ($examSubjects as $examSubject)
                                                    <option value="{{ $examSubject->id }}" @selected($row->exam_subject_id === $examSubject->id)>
                                                        {{ $examSubject->subject?->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="action" value="save" class="btn-secondary btn-sm">
                                                Save match
                                            </button>
                                            <button type="submit" name="action" value="ignore" class="btn-ghost btn-sm text-slate-500">
                                                Skip row
                                            </button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

@endsection
