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

            <a href="{{ route('admin.students-results.teachers.create') }}" class="btn-secondary btn-sm">
                <x-nav-icon name="user-plus" class="h-3.5 w-3.5" />
                Add teacher
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-12 border-b border-r border-line p-3 text-center">#</th>
                        <th class="border-b border-r border-line p-3">Teacher</th>
                        <th class="border-b border-r border-line p-3">Contact</th>
                        <th class="border-b border-r border-line p-3">Class Teacher Of</th>
                        <th class="w-28 border-b border-line p-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($teachers as $teacher)
                        <tr class="transition-colors hover:bg-surface-3/60">
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

                            <td class="p-3 text-center align-top">
                                <span @class([
                                    'badge-neutral',
                                    'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900' => ! $teacher->is_active,
                                ])>
                                    {{ $teacher->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-8 text-center text-sm text-muted">
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
    </div>
@endsection
