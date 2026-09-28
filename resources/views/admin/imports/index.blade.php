@extends('layouts.admin')

@section('title', 'Scoresheet imports')
@section('subtitle', 'Upload marked sheets — Excel, CSV, or a photograph for AI to read')

@section('content')

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ================= Upload ================= --}}
    <div class="lg:col-span-1">
        <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="card-pad">
            @csrf

            <h2 class="text-base font-semibold text-slate-900">Upload a scoresheet</h2>
            <p class="mt-1 text-sm text-slate-500">
                Nothing is saved to the examination until you review the reading and commit it.
            </p>

            <div class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
                <p class="font-medium text-slate-700">A sheet can carry every paper at once.</p>
                <p class="mt-1">
                    One row per candidate, one column per paper, each column headed with the paper's
                    name — the same shape as the score entry grid. A blank cell means that paper has
                    not been marked yet, which is not the same as an absence.
                </p>
            </div>

            <div class="mt-6 space-y-5">
                <x-field name="exam_id" label="Examination" type="select" required
                         placeholder-option="Choose an examination"
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

        {{-- ================= AI status ================= --}}
        <div @class([
            'card-pad mt-6',
            'bg-emerald-50/60' => $aiConfigured,
            'bg-slate-50' => ! $aiConfigured,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                    'bg-emerald-100 text-emerald-700' => $aiConfigured,
                    'bg-slate-200 text-slate-500' => ! $aiConfigured,
                ])>
                    <x-nav-icon name="upload" class="h-4 w-4" />
                </span>

                <div>
                    <h3 class="text-sm font-semibold {{ $aiConfigured ? 'text-emerald-900' : 'text-slate-800' }}">
                        AI reading is {{ $aiConfigured ? 'switched on' : 'not configured' }}
                    </h3>

                    @if ($aiConfigured)
                        <p class="mt-1.5 text-sm text-emerald-800">
                            Photographs and scans of marked sheets can be read automatically.
                            Handwriting is read as a proposal — you always review it before it counts.
                        </p>
                    @else
                        <p class="mt-1.5 text-sm text-slate-600">
                            Excel and CSV uploads work right now. To read photographs of
                            hand-marked sheets, set <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs ring-1 ring-slate-200">AI_PROVIDER</code>
                            and <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs ring-1 ring-slate-200">AI_API_KEY</code>
                            in your <code class="font-mono text-xs">.env</code> file.
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
                                <p class="max-w-xs truncate font-medium text-slate-900">{{ $import->original_name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $import->humanFileSize() }} · {{ $import->uploader?->name ?? 'Unknown' }}
                                    · {{ $import->created_at->diffForHumans() }}
                                </p>
                            </td>

                            <td class="text-sm">
                                <p class="text-slate-800">{{ $import->exam?->title }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $import->examSubject?->subject?->name ?? 'Whole examination' }}
                                </p>
                            </td>

                            <td class="text-sm text-slate-600">{{ $import->driver->label() }}</td>

                            <td class="text-center text-sm">
                                {{ $import->rows_total }}
                                @if ($import->rows_unmatched > 0)
                                    <p class="text-xs font-medium text-rose-600">{{ $import->rows_unmatched }} flagged</p>
                                @endif
                            </td>

                            <td><x-status-pill :status="$import->status" /></td>

                            <td class="text-right">
                                <a href="{{ route('admin.imports.show', $import) }}" class="btn-ghost btn-sm">Review</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <p class="text-sm font-medium text-slate-900">No scoresheets uploaded yet</p>
                                <p class="mt-1 text-sm text-slate-500">Use the form to upload the first one.</p>
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
