@extends('layouts.admin')

@section('title', 'Teachers List')

@section('content')
    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface-2 px-5 py-4">
            <div>
                <p class="font-display text-base font-semibold text-ink">Teachers List</p>
                <p class="mt-1 text-sm text-muted">
                    Every teacher on the staff, and the class each one is class teacher of.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.students-results.teachers.create') }}" class="btn-secondary btn-sm">
                    <x-nav-icon name="user-plus" class="h-3.5 w-3.5" />
                    Add teacher
                </a>

                <a href="{{ route('admin.students-results.teachers.import') }}" class="btn-secondary btn-sm">
                    <x-nav-icon name="upload" class="h-3.5 w-3.5" />
                    Bulk upload
                </a>
            </div>
        </div>

        {{-- One form for the whole list, so the boxes can be ticked and removed together.
             The bin on a row submits the small form waiting outside the table: a form
             inside a form is not a thing, and the `form` attribute is the way HTML says
             which one a button belongs to. --}}
        <form method="POST" action="{{ route('admin.students-results.teachers.destroy-selected') }}"
              x-data="{ count: 0 }"
              @change="count = $el.querySelectorAll('.teacher-check:checked').length"
              onsubmit="var n = this.querySelectorAll('.teacher-check:checked').length; return n > 0 && confirm('Remove ' + n + ' teacher account(s)? This cannot be undone.');">
            @csrf
            @method('DELETE')

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <p class="text-xs text-muted">
                    Tick the ones who have left, or use the bin beside a single name. Marking a
                    teacher inactive keeps the record and only takes away the login.
                </p>

                <button type="submit" class="btn-danger btn-sm" :disabled="count === 0">
                    <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                    Remove selected<span x-show="count > 0" x-cloak> (<span x-text="count"></span>)</span>
                </button>
            </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-10 border-b border-r border-line p-3 text-center">
                            <input type="checkbox"
                                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                   aria-label="Tick every teacher in the list"
                                   title="Tick every teacher in the list"
                                   @change="$root.querySelectorAll('.teacher-check').forEach(box => box.checked = $el.checked);
                                            count = $root.querySelectorAll('.teacher-check:checked').length">
                        </th>
                        <th class="w-12 border-b border-r border-line p-3 text-center">#</th>
                        <th class="border-b border-r border-line p-3">Teacher</th>
                        <th class="border-b border-r border-line p-3">Contact</th>
                        <th class="border-b border-r border-line p-3">Class Teacher Of</th>
                        <th class="w-28 border-b border-r border-line p-3 text-center">Status</th>
                        <th class="w-28 border-b border-line p-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($teachers as $teacher)
                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 text-center align-top">
                                <input type="checkbox"
                                       name="teachers[]"
                                       value="{{ $teacher->id }}"
                                       class="teacher-check h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                       aria-label="Remove {{ $teacher->name }}">
                            </td>

                            <td class="border-r border-line p-3 text-center align-top">{{ $loop->iteration }}</td>

                            <td class="border-r border-line p-3 align-top">
                                <div class="flex items-center gap-3">
                                    @if ($teacher->avatar_path)
                                        <img src="{{ asset('storage/' . $teacher->avatar_path) }}"
                                             alt="Photograph of {{ $teacher->name }}"
                                             class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    @else
                                        {{-- No picture is not an empty cell: it is the teacher's initials, the
                                             same as the top bar falls back to. --}}
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-900 text-xs font-semibold text-gold-300"
                                              aria-hidden="true">
                                            {{ $teacher->initials }}
                                        </span>
                                    @endif

                                    <span class="min-w-0">
                                        <span class="block font-medium text-ink">{{ $teacher->name }}</span>
                                        <span class="mt-0.5 block text-xs text-muted">
                                            Signs in as {{ $teacher->primaryRole() }}
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td class="border-r border-line p-3 align-top">
                                <span class="block">{{ $teacher->email }}</span>
                                <span class="mt-0.5 block text-xs text-muted">
                                    {{ $teacher->phone ?: 'No phone number' }}
                                </span>
                            </td>

                            <td class="border-r border-line p-3 align-top">
                                {{ $teacher->taughtClasses->isEmpty()
                                    ? 'Not a class teacher yet.'
                                    : $teacher->taughtClasses->pluck('name')->implode(', ') }}
                            </td>

                            <td class="border-r border-line p-3 text-center align-top">
                                <span @class([
                                    'badge-neutral',
                                    'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900' => ! $teacher->is_active,
                                ])>
                                    {{ $teacher->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>

                            <td class="p-3 align-top">
                                <div class="flex justify-center gap-2">
                                    <a href="{{ route('admin.students-results.teachers.edit', $teacher) }}"
                                       class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                       title="Edit {{ $teacher->name }}">
                                        <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                    </a>

                                    <button type="submit"
                                            form="remove-teacher-{{ $teacher->id }}"
                                            class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-600 dark:hover:border-rose-900 dark:hover:bg-rose-950/40 dark:hover:text-rose-300"
                                            title="Remove {{ $teacher->name }}">
                                        <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8 text-center text-sm text-muted">
                                No teachers yet.
                                <a href="{{ route('admin.students-results.teachers.create') }}"
                                   class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-200">
                                    Add the first one
                                </a>
                                — a class can only be given a class teacher once somebody is on the staff.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </form>
    </div>

    {{-- One form per row, waiting outside the table: the bin above points at these. --}}
    @foreach ($teachers as $teacher)
        <form id="remove-teacher-{{ $teacher->id }}"
              method="POST"
              action="{{ route('admin.students-results.teachers.destroy', $teacher) }}"
              onsubmit="return confirm('Remove {{ addslashes($teacher->name) }} from the teaching staff? @if (($held[$teacher->id] ?? '') !== '') They are still the class teacher of {{ addslashes($held[$teacher->id]) }}, and those classes will be left with nobody. @endif This cannot be undone.')">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endsection
