@extends('layouts.admin')

@section('title', 'Bulk upload teachers')

@section('content')
    {{-- ================= Upload ================= --}}
    <div class="card-pad">
        <p class="eyebrow">Step 1</p>
        <h2 class="mt-1 font-display text-lg font-semibold text-ink">Choose the spreadsheet</h2>
        <p class="mt-1 max-w-3xl text-sm text-muted">
            A CSV or Excel file with the column headings across the top. Nothing is created yet — the next
            screen shows exactly which accounts would be opened, and flags anything that needs fixing.
        </p>

        <div class="mt-5 flex flex-wrap items-end gap-4">
            <form method="POST" action="{{ route('admin.students-results.teachers.import.preview') }}"
                  enctype="multipart/form-data" class="flex flex-1 flex-wrap items-end gap-4">
                @csrf

                <div class="min-w-64 flex-1">
                    <label for="file" class="label">Spreadsheet</label>

                    <input id="file" name="file" type="file" accept=".csv,.txt,.xlsx,.xls" required
                           class="input file:mr-3 file:rounded-md file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-sm">

                    @error('file')
                        <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror

                    <p class="mt-1 text-xs text-muted">
                        Up to 8 MB, and up to {{ \App\Services\Teachers\TeacherImportService::MAX_ROWS }} teachers per file.
                    </p>
                </div>

                <button type="submit" class="btn-primary btn-sm">Read the file</button>
            </form>

            <a href="{{ route('admin.students-results.teachers.import.template') }}" class="btn-secondary btn-sm">
                Download the template
            </a>
        </div>
    </div>

    {{-- ================= Column guide ================= --}}
    <details class="card-pad mt-6" @if (! $staged) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-soft">
            Which columns does it understand?
        </summary>

        <p class="mt-3 text-sm text-muted">
            The template carries only the columns that are actually required. Everything below them is
            optional: add the column and it will be read, leave it out and nothing is lost. Headings are
            matched loosely, so “Name”, “name” and “Teacher name” all work and the order does not matter.
            A sheet that keeps the surname, the given name and the middle name in columns of their own is
            read too, and the names are joined into one. Any column it does not recognise is simply ignored.
        </p>

        <div class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2">
            @foreach ($columns as $column)
                <div class="flex items-start gap-3 border-b border-line-soft pb-3">
                    <span class="mt-0.5 shrink-0">
                        @if ($column['required'])
                            <span class="badge-neutral bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-900/30 dark:text-brand-200">Required</span>
                        @else
                            <span class="badge-neutral">Optional</span>
                        @endif
                    </span>

                    <div class="min-w-0">
                        <p class="font-mono text-xs font-medium text-ink">{{ $column['label'] }}</p>
                        <p class="mt-0.5 text-xs text-muted">{{ $column['note'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 rounded-xl bg-surface-2 p-3 text-xs text-ink-soft ring-1 ring-line">
            Every teacher added this way gets a login with the Teacher role, and has to change the password
            the first time they sign in. Photographs are added one at a time afterwards, from the pencil
            beside their name on the register.
        </p>
    </details>

    {{-- ================= Review ================= --}}
    @if ($staged)
        @php
            $rows = $staged['rows'];
            $okRows = collect($rows)->where('errors', []);
            $badRows = collect($rows)->filter(fn ($row) => $row['errors'] !== []);
        @endphp

        <form method="POST" action="{{ route('admin.students-results.teachers.import.commit') }}" class="mt-6"
              x-data="{ all: true }">
            @csrf

            <div class="card-pad">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="eyebrow">Step 2</p>
                        <h2 class="mt-1 font-display text-lg font-semibold text-ink">Check before adding</h2>
                        <p class="mt-1 text-sm text-muted">
                            Read from <span class="font-medium text-ink-soft">{{ $staged['filename'] }}</span>.
                            Untick anybody you do not want to add.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span class="badge-neutral bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-400/20">
                            {{ $okRows->count() }} ready
                        </span>

                        @if ($badRows->isNotEmpty())
                            <span class="badge-neutral bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-400/20">
                                {{ $badRows->count() }} need fixing
                            </span>
                        @endif
                    </div>
                </div>

                @if ($staged['truncated'] ?? false)
                    <x-alert tone="warning" class="mt-4">
                        This file had more than {{ \App\Services\Teachers\TeacherImportService::MAX_ROWS }} rows.
                        Only the first {{ \App\Services\Teachers\TeacherImportService::MAX_ROWS }} were read —
                        add these, then upload the rest as a second file.
                    </x-alert>
                @endif

                @if ($staged['ignored'] ?? [])
                    <p class="mt-4 rounded-xl bg-surface-2 p-3 text-xs text-ink-soft ring-1 ring-line">
                        Ignored columns: {{ implode(', ', $staged['ignored']) }}
                    </p>
                @endif
            </div>

            {{-- Rows that cannot be added, shown first so they are not missed. --}}
            @if ($badRows->isNotEmpty())
                <div class="card-pad mt-6 border-rose-200 dark:border-rose-900/60">
                    <h3 class="font-display text-base font-semibold text-ink">Needs fixing</h3>

                    <ul class="mt-3 space-y-2">
                        @foreach ($badRows as $row)
                            <li class="flex items-start gap-3 text-sm">
                                <span class="mt-0.5 shrink-0 font-mono text-xs text-muted">Row {{ $row['line'] }}</span>
                                <span class="min-w-0 text-ink-soft">
                                    <span class="font-medium text-ink">{{ $row['data']['name'] ?? 'No name' }}</span>
                                    — {{ implode(' ', $row['errors']) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 text-xs text-muted">
                        Fix them in the sheet and upload it again, or leave them unticked and add them by hand later.
                    </p>
                </div>
            @endif

            @if ($okRows->isNotEmpty())
                <div class="mt-6 table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-12 text-center">
                                    <input type="checkbox" x-model="all" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500"
                                           aria-label="All teachers">
                                </th>
                                <th class="w-16">Row</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($okRows as $row)
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="lines[]" value="{{ $row['line'] }}"
                                               x-model="all" x-bind:checked="all"
                                               class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500"
                                               aria-label="Add {{ $row['data']['name'] }}">
                                    </td>
                                    <td class="font-mono text-xs text-muted">{{ $row['line'] }}</td>
                                    <td class="font-medium text-ink">{{ $row['data']['name'] }}</td>
                                    <td>{{ $row['data']['phone'] }}</td>
                                    <td>{{ $row['data']['email'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Asked for here rather than with the file: it is given out by hand,
                     and every account made this way is made to change it. --}}
                <div class="card-pad mt-6">
                    <h3 class="font-display text-base font-semibold text-ink">The password to give them</h3>
                    <p class="mt-1 text-sm text-muted">
                        Every teacher in this upload is given this password, and has to change it the first
                        time they sign in.
                    </p>

                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <x-field name="password" type="password" label="Password" required
                                 hint="At least 8 characters." autocomplete="new-password" />

                        <x-field name="password_confirmation" type="password" label="Confirm password" required
                                 autocomplete="new-password" />
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-end gap-4 border-t border-line-soft pt-5">
                        <a href="{{ route('admin.students-results.teachers.list') }}"
                           class="text-xs text-muted transition-colors hover:text-ink-soft">
                            Cancel
                        </a>

                        <button type="submit" class="btn-primary btn-sm">
                            <x-nav-icon name="check" class="h-3.5 w-3.5" />
                            Add {{ $okRows->count() }} teacher(s)
                        </button>
                    </div>
                </div>
            @endif
        </form>
    @endif
@endsection
