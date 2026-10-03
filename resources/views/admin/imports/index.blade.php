@extends('layouts.admin')

@section('title', 'Scoresheet imports')
@section('subtitle', 'Upload marked sheets — Excel, CSV, or a photograph for AI to read')

@section('content')

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ================= Upload ================= --}}
    <div class="lg:col-span-1" x-data="{ exam: '{{ request('exam') }}' }">
        <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="card-pad">
            @csrf

            <h2 class="text-base font-semibold text-ink">Upload a scoresheet</h2>
            <p class="mt-1 text-sm text-muted">
                Nothing is saved to the examination until you review the reading and commit it.
            </p>

            <div class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-xs text-ink-soft ring-1 ring-line">
                <p class="font-medium text-ink-soft">A sheet can carry every paper at once.</p>
                <p class="mt-1">
                    One row per candidate, one column per paper, each column headed with the paper's
                    name — the same shape as the score entry grid. A blank cell means that paper has
                    not been marked yet, which is not the same as an absence.
                </p>
                <p class="mt-1.5">
                    Not sure of the layout? Download the blank sheet below — it is already laid out
                    that way, with the candidates' names on it.
                </p>
            </div>

            <div class="mt-6 space-y-5">
                {{-- The examination drives everything else on this form, including which
                     blank sheet can be downloaded, so the download follows this choice. --}}
                <x-field name="exam_id" label="Examination" type="select" required
                         placeholder-option="Choose an examination"
                         x-model="exam"
                         :value="request('exam')"
                         :options="$exams->mapWithKeys(fn ($e) => [$e->id => $e->title . ' — ' . ($e->level?->name ?? 'All levels')])->all()" />

                <x-field name="exam_subject_id" label="Subject (optional)" type="select"
                         placeholder-option="No — the sheet names its own papers"
                         hint="Leave this alone if the sheet has a column for each paper, or a Subject column of its own. Choose a paper only when the whole sheet is that one paper and it never says so."
                         :options="$subjectOptions" />

                <x-field name="file" label="Scoresheet file" type="file" required
                         accept=".xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.webp,.pdf"
                         hint="Excel/CSV, or a photo or scan of the marked sheet. Max 12 MB." />
            </div>

            <button type="submit" class="btn-primary mt-6 w-full">Upload &amp; read</button>
        </form>

        {{-- ================= Blank sheet ================= --}}
        {{-- Outside the upload form because it is not part of it: it is a GET, and
             nesting forms is not allowed. It follows the examination chosen above. --}}
        <div class="card-pad mt-6">
            <h3 class="text-sm font-semibold text-ink">Start from a blank sheet</h3>

            <p class="mt-1.5 text-sm text-muted">
                A ready-made CSV for this examination: every registered candidate already listed, and
                one column per paper. Fill in the marks and upload it back — a blank cell is read as
                “not marked yet”.
            </p>

            <template x-if="exam">
                <a x-bind:href="'{{ url('admin/exams') }}/' + exam + '/scoresheet'"
                   class="btn-secondary btn-sm mt-4 w-full">
                    Download the blank sheet
                </a>
            </template>

            <template x-if="! exam">
                <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted ring-1 ring-line">
                    Choose an examination above first — the sheet needs to know which papers and which
                    candidates it is for.
                </p>
            </template>
        </div>

        {{-- ================= AI status ================= --}}
        <div @class([
            'card-pad mt-6',
            'bg-emerald-50/60' => $aiConfigured,
            'bg-surface-2' => ! $aiConfigured,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                    'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300' => $aiConfigured,
                    'bg-surface-3 text-muted' => ! $aiConfigured,
                ])>
                    <x-nav-icon name="upload" class="h-4 w-4" />
                </span>

                <div>
                    <h3 class="text-sm font-semibold {{ $aiConfigured ? 'text-emerald-900 dark:text-emerald-100' : 'text-ink-soft' }}">
                        AI reading is {{ $aiConfigured ? 'switched on' : 'not configured' }}
                    </h3>

                    @if ($aiConfigured)
                        <p class="mt-1.5 text-sm text-emerald-800 dark:text-emerald-200">
                            Photographs and scans of marked sheets can be read automatically.
                            Handwriting is read as a proposal — you always review it before it counts.
                        </p>
                    @else
                        <p class="mt-1.5 text-sm text-ink-soft">
                            Excel and CSV uploads work right now. To read photographs of
                            hand-marked sheets, a key for an AI provider has to be set up
                            @can('settings.manage')
                                in <a href="{{ route('admin.settings.api') }}" class="font-medium underline decoration-dotted">Settings → API</a>.
                            @else
                                — ask an administrator to set one up in Settings → API.
                            @endcan
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ================= History ================= --}}
    <div class="lg:col-span-2">
        <form method="GET" class="card-pad mb-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-field name="exam" label="Filter by examination" type="select"
                             placeholder-option="All examinations"
                             :value="request('exam')"
                             :options="$exams->pluck('title', 'id')->all()" />
                </div>

                <x-field name="status" label="Status" type="select"
                         placeholder-option="All statuses"
                         :value="request('status')"
                         :options="\App\Enums\ScoreImportStatus::options()" />
            </div>

            <div class="mt-4 flex gap-3">
                <button type="submit" class="btn-secondary btn-sm">Filter</button>
                @if (request()->hasAny(['exam', 'status']))
                    <a href="{{ route('admin.imports.index') }}" class="btn-ghost btn-sm">Clear</a>
                @endif
            </div>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Examination</th>
                        <th>Read by</th>
                        <th class="text-center">Rows</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($imports as $import)
                        <tr>
                            <td>
                                <p class="max-w-xs truncate font-medium text-ink">{{ $import->original_name }}</p>
                                <p class="text-xs text-muted">
                                    {{ $import->humanFileSize() }} · {{ $import->uploader?->name ?? 'Unknown' }}
                                    · {{ $import->created_at->diffForHumans() }}
                                </p>
                            </td>

                            <td class="text-sm">
                                <p class="text-ink-soft">{{ $import->exam?->title }}</p>
                                <p class="text-xs text-muted">
                                    {{ $import->examSubject?->subject?->name ?? 'Whole examination' }}
                                </p>
                            </td>

                            <td class="text-sm text-ink-soft">{{ $import->driver->label() }}</td>

                            <td class="text-center text-sm">
                                {{ $import->rows_total }}
                                @if ($import->rows_unmatched > 0)
                                    <p class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ $import->rows_unmatched }} flagged</p>
                                @endif
                            </td>

                            <td><x-status-pill :status="$import->status" /></td>

                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('admin.imports.show', $import) }}" class="btn-ghost btn-sm">Review</a>

                                    @can('scores.import')
                                        {{-- Offered only where it would work: a committed
                                             import is kept for audit and the controller
                                             refuses to delete it, so a button that
                                             always errored would be worse than none. --}}
                                        @if ($import->status !== \App\Enums\ScoreImportStatus::Committed)
                                            <form method="POST" action="{{ route('admin.imports.destroy', $import) }}"
                                                  onsubmit="return confirm('Delete this upload? The file and its review list are removed. No marks have been committed from it yet.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:bg-rose-950/40">
                                                    Delete
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <p class="text-sm font-medium text-ink">No scoresheets uploaded yet</p>
                                <p class="mt-1 text-sm text-muted">Use the form to upload the first one.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">{{ $imports->links() }}</div>
    </div>
</div>

@endsection
