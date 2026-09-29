@extends('layouts.admin')

@section('title', 'Bulk register applicants')
@section('subtitle', 'Upload a spreadsheet of candidates instead of typing them one by one')

@section('actions')
    <a href="{{ route('admin.applicants.create') }}" class="btn-secondary btn-sm">Enter one by hand</a>
    <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Back to applicants</a>
@endsection

@section('content')

{{-- ================= Upload ================= --}}
<div class="card-pad">
    <p class="eyebrow">Step 1</p>
    <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Choose the spreadsheet</h2>
    <p class="mt-1 max-w-3xl text-sm text-slate-500">
        A CSV or Excel file with the column headings across the top. Nothing is registered yet — the next
        screen shows exactly what will be created, and flags anything that needs fixing.
    </p>

    <div class="mt-5 flex flex-wrap items-end gap-4">
        <form method="POST" action="{{ route('admin.applicants.import.preview') }}"
              enctype="multipart/form-data" class="flex flex-1 flex-wrap items-end gap-4">
            @csrf

            <div class="min-w-64 flex-1">
                <label for="file" class="label">Spreadsheet</label>
                <input id="file" name="file" type="file" accept=".csv,.txt,.xlsx,.xls" required
                       class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm">
                @error('file')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-slate-500">Up to 8 MB, and up to {{ \App\Services\Admissions\ApplicantImportService::MAX_ROWS }} candidates per file.</p>
            </div>

            <button type="submit" class="btn-primary btn-sm">Read the file</button>
        </form>

        <a href="{{ route('admin.applicants.import.template') }}" class="btn-secondary btn-sm">
            Download the template
        </a>
    </div>
</div>

{{-- ================= Column guide ================= --}}
<details class="card-pad mt-6" @if (! $staged) open @endif>
    <summary class="cursor-pointer text-sm font-medium text-slate-700">
        Which columns does it understand?
    </summary>

    <p class="mt-3 text-sm text-slate-500">
        The template carries only the columns that are actually required — the same ones the office
        registration form insists on. Everything below them is optional: add a column and it will be read,
        leave it out and nothing is lost. Headings are matched loosely, so “Surname”, “surname” and
        “Family Name” all work and the order does not matter. Any column it does not recognise is simply
        ignored.
    </p>

    <div class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2">
        @foreach ($columns as $column)
            <div class="flex items-start gap-3 border-b border-slate-100 pb-3">
                <span class="mt-0.5 shrink-0">
                    @if ($column['required'])
                        <span class="badge bg-brand-50 text-brand-700 ring-brand-600/20">Required</span>
                    @else
                        <span class="badge bg-slate-100 text-slate-600 ring-slate-500/20">Optional</span>
                    @endif
                </span>

                <div class="min-w-0">
                    <p class="font-mono text-xs font-medium text-slate-900">{{ $column['label'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $column['note'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</details>

{{-- ================= Review ================= --}}
@if ($staged)
    @php
        $rows = $staged['rows'];
        $okRows = collect($rows)->where('errors', []);
        $badRows = collect($rows)->filter(fn ($row) => $row['errors'] !== []);
    @endphp

    <form method="POST" action="{{ route('admin.applicants.import.commit') }}" class="mt-6"
          x-data="{ all: true }">
        @csrf

        <div class="card-pad">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="eyebrow">Step 2</p>
                    <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Check before registering</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Read from <span class="font-medium text-slate-700">{{ $staged['filename'] }}</span>.
                        Untick anybody you do not want to register.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">
                        {{ $okRows->count() }} ready
                    </span>
                    @if ($badRows->isNotEmpty())
                        <span class="badge bg-rose-50 text-rose-700 ring-rose-600/20">
                            {{ $badRows->count() }} need fixing
                        </span>
                    @endif
                </div>
            </div>

            @if ($staged['truncated'] ?? false)
                <x-alert tone="warning" class="mt-4">
                    This file had more than {{ \App\Services\Admissions\ApplicantImportService::MAX_ROWS }} rows.
                    Only the first {{ \App\Services\Admissions\ApplicantImportService::MAX_ROWS }} were read —
                    register these, then upload the rest as a second file.
                </x-alert>
            @endif

            @if ($staged['ignored'] ?? [])
                <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-slate-200">
                    Ignored columns: {{ implode(', ', $staged['ignored']) }}
                </p>
            @endif
        </div>

        {{-- Rows that cannot be registered, shown first so they are not missed. --}}
        @if ($badRows->isNotEmpty())
            <div class="card-pad mt-6">
                <h3 class="font-display text-base font-semibold text-slate-900">
                    Rows that will be skipped
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    Correct these in the spreadsheet and upload it again, or register the rest now and fix
                    these afterwards by hand.
                </p>

                <div class="table-wrap mt-4">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-16">Row</th>
                                <th>Candidate</th>
                                <th>Class</th>
                                <th>What is wrong</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($badRows as $row)
                                <tr>
                                    <td class="font-mono text-xs text-slate-500">{{ $row['line'] }}</td>
                                    <td class="text-sm">{{ $row['name'] ?: '—' }}</td>
                                    <td class="text-sm">{{ $row['class'] ?: '—' }}</td>
                                    <td class="text-sm text-rose-700">{{ implode(' ', $row['errors']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Rows that will be created. --}}
        <div class="mt-6">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm font-medium text-slate-700">
                    {{ $okRows->count() }} candidate(s) to register
                </p>

                @if ($okRows->isNotEmpty())
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" x-model="all"
                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Select all
                    </label>
                @endif
            </div>

            <div class="table-wrap mt-3 max-h-[32rem] overflow-y-auto">
                <table class="table">
                    <thead class="sticky top-0">
                        <tr>
                            <th class="w-12"></th>
                            <th>Candidate</th>
                            <th>Class</th>
                            <th>Gender</th>
                            <th>Date of birth</th>
                            <th>Parent / guardian</th>
                            <th>Parent email</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($okRows as $row)
                            <tr>
                                <td>
                                    {{-- Ticked server-side too, so the form still works
                                         if the Select all helper never loads. --}}
                                    <input type="checkbox" name="lines[]" value="{{ $row['line'] }}"
                                           checked x-bind:checked="all"
                                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                </td>
                                <td>
                                    <span class="font-medium text-slate-900">{{ $row['name'] }}</span>

                                    {{-- A note, not an error: brothers and sisters share a
                                         parent's name and number, so this says "look",
                                         and the row stays ticked. --}}
                                    @if (! empty($row['duplicates']))
                                        <span class="mt-1 block text-xs font-medium text-amber-700">
                                            Possibly already on file as {{ implode(', ', $row['duplicates']) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-sm">{{ $row['class'] ?? '—' }}</td>
                                <td class="text-sm">{{ $row['gender'] ?? '—' }}</td>
                                <td class="text-sm">{{ $row['dob'] ?? '—' }}</td>
                                <td class="text-sm">{{ $row['parent'] ?? '—' }}</td>
                                <td class="text-sm">{{ $row['parent_email'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center">
                                    <p class="text-sm font-medium text-slate-900">No row in this file can be registered</p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Fix the rows listed above and upload the file again.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
            <p class="max-w-2xl text-sm text-slate-500">
                Each candidate gets the next registration number in turn, in the order they appear here.
                No text message is sent during a bulk upload — use the text messages screen afterwards.
            </p>

            <div class="ml-auto flex items-center gap-3">
                <a href="{{ route('admin.applicants.import') }}" class="btn-ghost btn-sm">Start over</a>
                <button type="submit" class="btn-primary btn-sm" @disabled($okRows->isEmpty())>
                    Register these candidates
                </button>
            </div>
        </div>
    </form>
@endif

@endsection
