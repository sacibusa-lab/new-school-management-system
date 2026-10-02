@extends('layouts.admin')

@section('title', 'Students Details')
@section('subtitle', 'Students & Results')

@section('content')

{{-- ================= Class, section, and a button ================= --}}
<form method="GET" class="card-pad">
    {{-- Three equal columns, the button last and the same size as the two boxes it
         sits beside: at btn-sm it read as a smaller kind of thing than the filters,
         and the button is the point of the row. --}}
    <div class="grid items-end gap-4 sm:grid-cols-3">
        <x-field name="class" label="Class" type="select"
                 placeholder-option="All classes"
                 :value="$filters['class'] ?: null"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="section" label="Section" type="select"
                 placeholder-option="All sections"
                 :value="$filters['section'] ?: null"
                 :options="$sections->pluck('name', 'id')->all()" />

        <button type="submit" class="btn-primary w-full">Filter</button>
    </div>
</form>

{{-- ================= The roll ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Student List</p>
    </div>

    {{-- One form for the whole list, so the boxes can be ticked and removed together.
         The bin on a row submits the small form waiting outside the table: a form
         inside a form is not a thing, and the `form` attribute is the way HTML says
         which one a button belongs to. --}}
    <form method="POST" action="{{ route('admin.students-results.students.destroy-selected') }}"
          x-data="{ count: 0 }"
          @change="count = $el.querySelectorAll('.student-check:checked').length"
          onsubmit="var n = this.querySelectorAll('.student-check:checked').length; return n > 0 && confirm('Take ' + n + ' student(s) off the register? The records are kept and can be put back.');">
        @csrf
        @method('DELETE')

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <p class="text-xs text-muted">
                Tick the ones to take off the register, or use the bin beside a single name.
                Nothing is destroyed — the record is kept and can be put back.
            </p>

            <button type="submit" class="btn-danger btn-sm" :disabled="count === 0">
                <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                Bulk Delete<span x-show="count > 0" x-cloak> (<span x-text="count"></span>)</span>
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-10 border-b border-r border-line p-3 text-center">
                            <input type="checkbox"
                                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                   aria-label="Tick every student in the list"
                                   title="Tick every student in the list"
                                   @change="$root.querySelectorAll('.student-check').forEach(box => box.checked = $el.checked);
                                            count = $root.querySelectorAll('.student-check:checked').length">
                        </th>
                        <th class="w-16 border-b border-r border-line p-3 text-center">Photo</th>
                        <th class="border-b border-r border-line p-3">Name</th>
                        <th class="w-40 border-b border-r border-line p-3">Admission No.</th>
                        <th class="w-48 border-b border-r border-line p-3">Guardian Name</th>
                        <th class="w-44 border-b border-r border-line p-3">Fees Progress</th>
                        <th class="w-28 border-b border-line p-3 text-center">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($students as $student)
                        @php
                            // Billed and paid are summed in the query, so a page of
                            // twenty-five costs two reads rather than fifty.
                            $billed = (float) ($student->billed_total ?? 0);
                            $paid = (float) ($student->paid_total ?? 0);
                            $percent = $billed > 0
                                ? (int) round(max(0, min(100, $paid / $billed * 100)))
                                : 0;
                        @endphp

                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 text-center align-middle">
                                <input type="checkbox"
                                       name="students[]"
                                       value="{{ $student->id }}"
                                       class="student-check h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                       aria-label="Take {{ $student->full_name }} off the register">
                            </td>

                            <td class="border-r border-line p-3 text-center align-middle">
                                @if ($student->photo_path)
                                    <img src="{{ asset('storage/'.$student->photo_path) }}"
                                         alt="Photograph of {{ $student->full_name }}"
                                         class="mx-auto h-10 w-10 rounded-full object-cover ring-1 ring-line">
                                @else
                                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-900 text-xs font-semibold text-gold-300"
                                          aria-hidden="true">{{ $student->initials }}</span>
                                @endif
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                <span class="block font-medium text-ink">{{ $student->full_name }}</span>
                                <span class="mt-0.5 block text-xs text-muted">
                                    {{ $student->schoolClass?->name ?? $student->level?->name ?? 'No class yet' }}
                                </span>
                            </td>

                            <td class="border-r border-line p-3 align-middle font-mono text-xs">
                                {{ $student->admission_number ?? '—' }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                @if ($student->guardian_name)
                                    <span class="block">{{ $student->guardian_name }}</span>

                                    @if ($student->guardian_phone)
                                        <span class="mt-0.5 block font-mono text-xs text-muted">{{ $student->guardian_phone }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                <div class="flex items-center gap-2">
                                    <span class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-3">
                                        <span @class([
                                                  'block h-full rounded-full',
                                                  'bg-emerald-500' => $percent >= 100,
                                                  'bg-brand-600' => $percent < 100,
                                              ])
                                              style="width: {{ $percent }}%"></span>
                                    </span>

                                    @if ($billed > 0)
                                        <span class="text-xs font-medium text-ink">{{ $percent }}%</span>
                                    @else
                                        {{-- No invoice is not the same as nothing paid, and a
                                             confident 0% would say the family owes everything. --}}
                                        <span class="text-xs text-muted">No invoice</span>
                                    @endif
                                </div>
                            </td>

                            <td class="p-3 align-middle">
                                <div class="flex justify-center gap-2">
                                    <a href="{{ route('admin.students.show', $student) }}"
                                       class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                       title="Open {{ $student->full_name }}">
                                        <x-nav-icon name="eye" class="h-3.5 w-3.5" />
                                    </a>

                                    {{-- The name is left out of the question on purpose: it
                                         would have to be escaped into the script, and a name
                                         with an apostrophe in it would break the page. --}}
                                    <button type="submit"
                                            form="remove-student-{{ $student->id }}"
                                            class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-600 dark:hover:border-rose-900 dark:hover:bg-rose-950/40 dark:hover:text-rose-300"
                                            title="Take {{ $student->full_name }} off the register">
                                        <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8 text-center text-sm text-muted">
                                Nobody to show. No student matches that class and section — try a
                                different pair, or clear both to see the whole roll.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
</div>

{{-- One form per row, waiting outside the table: the bin above points at these. --}}
@foreach ($students as $student)
    <form id="remove-student-{{ $student->id }}"
          method="POST"
          action="{{ route('admin.students-results.students.destroy', $student) }}"
          onsubmit="return confirm('Take this student off the register? The record is kept and can be put back.');">
        @csrf
        @method('DELETE')
    </form>
@endforeach

@if ($students->hasPages())
    <div class="mt-5">{{ $students->links() }}</div>
@endif

@endsection
