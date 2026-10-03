@extends('layouts.admin')

@section('title', 'Multiple import')

@php
    // Which sections the school actually runs under each year group, so the second
    // dropdown can offer only the arms that exist: JSS1 and C are JSS1C, and a school
    // with no JSS1C has no JSS1C to put children in.
    $classPairs = $levels->mapWithKeys(fn ($level) => [
        (string) $level->id => $sections
            ->filter(fn ($section) => $level->classes->contains('section_id', $section->id))
            ->mapWithKeys(fn ($section) => [(string) $section->id => $section->name])
            ->all(),
    ])->all();
@endphp

@section('content')
    {{-- ================= Step 1: the class, and the file ================= --}}
    <form method="POST" action="{{ route('admin.students-results.students.multiple-import.preview') }}"
          enctype="multipart/form-data"
          class="card-pad"
          x-data="{
              level: @js((string) old('level_id', '')),
              section: @js((string) old('section_id', '')),
              pairs: @js($classPairs),
              file: null,
              over: false,
              get arms() { return this.pairs[this.level] ?? {}; },
          }">
        @csrf

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="eyebrow">Step 1</p>
                <h2 class="mt-1 font-display text-lg font-semibold text-ink">Choose the class and the file</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted">
                    A CSV or Excel file with the column headings across the top. Nothing is created yet — the next
                    screen shows every child that was read, with the line each one came from, and flags anything
                    that needs fixing. The class you choose here is where all of them go, so the file itself does
                    not carry a class column.
                </p>
            </div>

            <a href="{{ route('admin.students-results.students.multiple-import.template') }}"
               class="btn-secondary btn-sm shrink-0">
                <x-nav-icon name="download" class="h-3.5 w-3.5" />
                Download Sample Import File
            </a>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            {{-- The two halves of a class, asked for the way the school says them. --}}
            <div>
                <label for="level_id" class="label">
                    Class <span class="text-rose-500">*</span>
                </label>

                <select id="level_id" name="level_id" required x-model="level" @change="section = ''"
                        class="input @error('level_id') input-error @enderror">
                    <option value="">Choose a class</option>

                    @foreach ($levels as $level)
                        <option value="{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
                </select>

                @error('level_id')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="section_id" class="label">
                    Section <span class="text-rose-500">*</span>
                </label>

                {{-- Left shut until a class is chosen, because the arms on offer are the
                     arms that class runs — asking for the section first would offer
                     nothing, or offer arms the year group does not have. --}}
                <select id="section_id" name="section_id" required
                        x-model="section" :disabled="level === ''"
                        class="input disabled:cursor-not-allowed disabled:bg-surface-2 @error('section_id') input-error @enderror">
                    <option value="" x-text="level === '' ? 'Select Class First' : 'Choose a section'"></option>

                    <template x-for="(name, id) in arms" :key="id">
                        <option :value="id" :selected="id === section" x-text="name"></option>
                    </template>
                </select>

                @error('section_id')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- ================= The file ================= --}}
        <div class="mt-5">
            <label class="label">
                Select CSV File <span class="text-rose-500">*</span>
            </label>

            {{-- A label wrapping the whole box, so a click anywhere in it opens the
                 picker. A dropped file is put straight onto the input, which is what
                 makes dropping and choosing the same thing to the form. --}}
            <label for="file"
                   @dragover.prevent="over = true"
                   @dragleave.prevent="over = false"
                   @drop.prevent="over = false; $refs.file.files = $event.dataTransfer.files; file = $event.dataTransfer.files[0]?.name ?? null"
                   :class="over
                       ? 'border-brand-400 bg-brand-50/60 dark:bg-brand-900/20'
                       : 'border-line bg-surface-2/40'"
                   class="mt-1 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center transition-colors">
                <x-nav-icon name="upload" class="h-8 w-8 text-muted" />

                <span class="text-sm font-medium text-ink-soft"
                      x-text="file ?? 'Drag and drop a file here or click'"></span>

                <span class="text-xs text-muted">
                    CSV or Excel — .csv, .xlsx or .xls — up to 8 MB, and up to
                    {{ number_format($maxRows) }} children per file.
                </span>
            </label>

            <input x-ref="file" id="file" name="file" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="sr-only"
                   @change="file = $event.target.files[0]?.name ?? null">

            @error('file')
                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-5">
            <button type="submit" class="btn-primary">Read the file</button>
        </div>
    </form>

    {{-- ================= Column guide ================= --}}
    <details class="card-pad mt-6" @if (! $staged) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-soft">
            Which columns does it understand?
        </summary>

        <p class="mt-3 max-w-3xl text-sm text-muted">
            The sample file carries every column below, so the easiest thing is to fill it in and delete the ones
            the school has no data for. Headings are matched loosely — “Surname”, “surname” and “Last name” all
            work, and the order does not matter. Anything it does not recognise is ignored and reported back, so
            no column is read as something it is not.
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
            Every child added this way is given an admission number of their own — the
            <span class="font-mono">SAC/2026/001</span> the school knows them by — and a portal login, so the
            results and fees screens work for them from the day they arrive. They sign in with that number, and
            it is printed on the register this finishes on. No bill is raised here: fees are invoiced from the
            Fees screen when they fall due. Photographs are added afterwards, one at a time, from the child's
            own page.
        </p>
    </details>

    {{-- ================= Step 2: what was read ================= --}}
    @if ($staged)
        @php
            $rows = $staged['rows'];
            $okRows = collect($rows)->where('errors', []);
            $badRows = collect($rows)->filter(fn ($row) => $row['errors'] !== []);
        @endphp

        <form method="POST" action="{{ route('admin.students-results.students.multiple-import.commit') }}"
              class="mt-6"
              x-data="{ count: {{ $okRows->count() }} }"
              @change="count = $el.querySelectorAll('.line-check:checked').length">
            @csrf

            <div class="card overflow-hidden">
                <div class="border-b border-line bg-surface-2 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="eyebrow">Step 2</p>
                            <h2 class="mt-1 font-display text-base font-semibold text-ink">Check before adding</h2>
                            <p class="mt-1 text-sm text-muted">
                                Read from <span class="font-medium text-ink-soft">{{ $staged['filename'] }}</span>,
                                for <span class="font-medium text-ink-soft">{{ $staged['class_name'] }}</span>.
                                Untick anybody who should not go in.
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

                    @if ($staged['truncated'])
                        <x-alert tone="warning" class="mt-4">
                            This file had more than {{ number_format($maxRows) }} rows. Only the first
                            {{ number_format($maxRows) }} were read — add these, then upload the rest as a
                            second file.
                        </x-alert>
                    @endif

                    @if ($staged['unread'] !== [])
                        <x-alert tone="warning" class="mt-4">
                            These columns were not recognised and were left alone:
                            <span class="font-medium">{{ implode(', ', $staged['unread']) }}</span>.
                            If one of them was meant to be a name or a guardian, rename it to match the sample
                            file and read the sheet again.
                        </x-alert>
                    @endif

                    <p class="mt-4 text-xs text-muted">
                        A row that needs fixing cannot be ticked — nothing is written from it. A note is not a
                        problem: a name already on the roll is worth a look, but two children in one class can
                        honestly share a name.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-soft">
                        <input type="checkbox"
                               class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                               @change="const form = $el.closest('form');
                                        form.querySelectorAll('.line-check').forEach(box => { if (! box.disabled) { box.checked = $el.checked; } });
                                        count = form.querySelectorAll('.line-check:checked').length">
                        Tick everybody that can be added
                    </label>

                    <button type="submit" class="btn-primary btn-sm" :disabled="count === 0">
                        Import<span x-show="count > 0" x-cloak> (<span x-text="count"></span>)</span>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-line text-sm">
                        <thead>
                            <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                                <th class="w-14 border-b border-r border-line p-3 text-center">Add</th>
                                <th class="w-16 border-b border-r border-line p-3 text-center">Line</th>
                                <th class="w-64 border-b border-r border-line p-3">Name</th>
                                <th class="border-b border-line p-3">What the sheet says</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line text-ink-soft">
                            @foreach ($rows as $row)
                                @php $blocked = $row['errors'] !== []; @endphp

                                <tr @class([
                                    'align-top',
                                    'bg-rose-50/40 dark:bg-rose-950/20' => $blocked,
                                ])>
                                    <td class="border-r border-line p-3 text-center">
                                        <input type="checkbox"
                                               name="lines[]"
                                               value="{{ $row['line'] }}"
                                               class="line-check h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                               @disabled($blocked)
                                               aria-label="Add the child on line {{ $row['line'] }}">
                                    </td>

                                    <td class="border-r border-line p-3 text-center text-xs text-muted">
                                        {{ $row['line'] }}
                                    </td>

                                    <td class="border-r border-line p-3">
                                        <span class="block font-medium text-ink">{{ $row['name'] }}</span>

                                        @if ($blocked)
                                            <span class="mt-0.5 block text-xs font-medium text-rose-700 dark:text-rose-300">
                                                {{ implode(' ', $row['errors']) }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="border-line p-3">
                                        <span class="block text-xs">
                                            {{ collect([
                                                $row['values']['gender'] ? ucfirst($row['values']['gender']) : null,
                                                $row['values']['date_of_birth'],
                                            ])->filter()->implode(' · ') ?: '—' }}
                                        </span>

                                        @if ($row['values']['guardian_name'] || $row['values']['guardian_phone'])
                                            <span class="mt-0.5 block text-xs text-muted">
                                                {{ collect([
                                                    $row['values']['guardian_name'],
                                                    $row['values']['guardian_phone'],
                                                ])->filter()->implode(' · ') }}
                                            </span>
                                        @endif

                                        @foreach ($row['notes'] as $note)
                                            <span class="mt-1 block text-xs text-amber-700 dark:text-amber-300">
                                                {{ $note }}
                                            </span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line px-5 py-4">
                    <a href="{{ route('admin.students-results.students.multiple-import') }}" class="btn-ghost btn-sm">
                        Start again
                    </a>

                    <button type="submit" class="btn-primary btn-sm" :disabled="count === 0">
                        Import
                    </button>
                </div>
            </div>
        </form>
    @endif
@endsection
