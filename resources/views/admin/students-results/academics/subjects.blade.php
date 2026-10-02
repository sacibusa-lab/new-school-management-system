@extends('layouts.admin')

@section('title', 'Subjects')

@section('content')
    <style>
        @media (min-width: 64rem) {
            .subject-entry-form {
                align-items: flex-end;
            }
        }
    </style>

    <div class="space-y-6">
        <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
                <h1 class="font-display text-xl font-semibold text-ink">Subject catalogue</h1>
                <p class="mt-1 text-sm text-muted">Maintain the shared subjects and codes used across academic records.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.students-results.academics.subjects.assignments') }}" class="btn-secondary btn-sm whitespace-nowrap">
                    <x-nav-icon name="clipboard-check" class="h-4 w-4" />
                    Class Assign
                </a>
                <p class="text-sm text-muted">{{ $subjects->total() }} {{ \Illuminate\Support\Str::plural('subject', $subjects->total()) }}</p>
            </div>
        </div>

        <section class="card p-5" aria-labelledby="subject-form-title">
            <div class="mb-4 flex items-center justify-between gap-4 border-b border-line-soft pb-3">
                <div>
                    <h2 id="subject-form-title" class="text-base font-semibold text-ink">
                        {{ $editing ? 'Edit subject' : 'Add a subject' }}
                    </h2>
                    <p class="mt-1 text-sm text-muted">Codes are stored in uppercase and must be unique.</p>
                </div>
                @if ($editing)
                    <a href="{{ route('admin.students-results.academics.subjects', request()->only('search', 'status')) }}"
                       class="text-sm font-medium text-ink-soft underline decoration-line underline-offset-4 hover:text-ink">
                        Cancel edit
                    </a>
                @endif
            </div>

            <form method="POST"
                  action="{{ $editing ? route('admin.students-results.academics.subjects.update', $editing) : route('admin.students-results.academics.subjects.store') }}"
                class="subject-entry-form flex flex-col gap-3 lg:flex-row">
                @csrf
                @if ($editing)
                    @method('PUT')
                @endif

                                <div class="min-w-0 flex-1">
                    <label for="subject-name" class="label">Subject name</label>
                    <input id="subject-name" name="name" type="text" maxlength="120" required
                           value="{{ old('name', $editing?->name) }}" placeholder="Mathematics" class="input">
                    @error('name')
                        <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="w-40 shrink-0">
                    <label for="subject-code" class="label">Code</label>
                    <input id="subject-code" name="code" type="text" maxlength="20" required
                           value="{{ old('code', $editing?->code) }}" placeholder="MTH" class="input uppercase">
                    @error('code')
                        <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn-secondary btn-sm h-10 shrink-0 whitespace-nowrap">
                    <x-nav-icon :name="$editing ? 'check' : 'plus'" class="h-4 w-4" />
                    {{ $editing ? 'Save changes' : 'Add subject' }}
                </button>
            </form>
        </section>

        <section class="card overflow-hidden" aria-labelledby="subject-list-title">
            <div class="flex flex-col justify-between gap-4 border-b border-line p-5 lg:flex-row lg:items-end">
                <div>
                    <h2 id="subject-list-title" class="text-base font-semibold text-ink">All subjects</h2>
                    <p class="mt-1 text-sm text-muted">Inactive subjects remain in historical records but cannot be selected for new work.</p>
                </div>

                <form method="GET" action="{{ route('admin.students-results.academics.subjects') }}"
                      class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label class="sr-only" for="subject-search">Search subjects</label>
                    <input id="subject-search" name="search" type="search" maxlength="100"
                           value="{{ $search }}" placeholder="Search name or code" class="input min-w-0 flex-1">

                    <label class="sr-only" for="subject-status">Filter by status</label>
                    <select id="subject-status" name="status" class="input w-40 shrink-0">
                        <option value="all" @selected($status === 'all')>All statuses</option>
                        <option value="active" @selected($status === 'active')>Active</option>
                        <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                    </select>

                    <button type="submit" class="btn-secondary btn-sm h-10 w-10 shrink-0 px-0" title="Search subjects" aria-label="Search subjects">
                        <x-nav-icon name="search" class="h-4 w-4" />
                    </button>
                    @if ($search !== '' || $status !== 'all')
                        <a href="{{ route('admin.students-results.academics.subjects') }}"
                           class="btn-ghost btn-sm shrink-0">Clear</a>
                    @endif
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th scope="col" class="border-b border-line px-5 py-3">Subject</th>
                            <th scope="col" class="border-b border-line px-5 py-3">Code</th>
                            <th scope="col" class="border-b border-line px-5 py-3">Status</th>
                            <th scope="col" class="border-b border-line px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink-soft">
                        @forelse ($subjects as $subject)
                            <tr class="transition-colors hover:bg-surface-3/60">
                                <td class="px-5 py-3 font-medium text-ink">{{ $subject->name }}</td>
                                <td class="px-5 py-3 font-mono text-xs">{{ $subject->code }}</td>
                                <td class="px-5 py-3">
                                    <span @class([
                                        'inline-flex items-center gap-2 text-xs font-semibold',
                                        'text-emerald-700 dark:text-emerald-300' => $subject->is_active,
                                        'text-muted' => ! $subject->is_active,
                                    ])>
                                        <span @class([
                                            'h-2 w-2 shrink-0 rounded-full',
                                            'bg-emerald-500 dark:bg-emerald-400' => $subject->is_active,
                                            'bg-ink-soft/50' => ! $subject->is_active,
                                        ])></span>
                                        {{ $subject->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.students-results.academics.subjects', array_merge(request()->only('search', 'status'), ['edit' => $subject->id])) }}"
                                           class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                           title="Edit {{ $subject->name }}" aria-label="Edit {{ $subject->name }}">
                                            <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                        </a>

                                        <form method="POST" action="{{ route('admin.students-results.academics.subjects.status', $subject) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $subject->is_active ? 0 : 1 }}">
                                            <button type="submit" class="btn-ghost btn-sm">
                                                {{ $subject->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center">
                                    <p class="font-medium text-ink">{{ $search !== '' || $status !== 'all' ? 'No matching subjects' : 'No subjects yet' }}</p>
                                    <p class="mt-1 text-sm text-muted">
                                        {{ $search !== '' || $status !== 'all' ? 'Try a different search or status filter.' : 'Add the school’s first subject above.' }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($subjects->hasPages())
                <div class="border-t border-line px-5 py-3">
                    {{ $subjects->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
