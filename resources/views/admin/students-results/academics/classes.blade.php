@extends('layouts.admin')

@section('title', 'Classes & Sections')

@section('content')

{{--
    A class is a class name and a section put together, and the sections have to exist
    first — so this is two tabs, and the Section tab is the one that answers "there is
    nothing in the dropdown".
--}}
<div x-data="{ tab: '{{ $tab }}' }">

    {{-- ================= Tabs ================= --}}
    <div class="border-b border-line">
        <div class="flex gap-8">
            @foreach ([['class', 'Class', 'academic'], ['section', 'Section', 'cog']] as [$key, $label, $icon])
                <button type="button"
                        @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}'
                            ? 'border-gold-400 text-ink'
                            : 'border-transparent text-muted hover:text-ink-soft'"
                        class="flex items-center gap-2 border-b-2 pb-3 text-sm font-medium transition-colors">
                    <x-nav-icon :name="$icon" class="h-4 w-4" />
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- ================= Class ================= --}}
    <div x-show="tab === 'class'" class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">

        {{-- Create, or edit the row the pencil was clicked on --}}
        <div class="card self-start p-5 lg:col-span-4">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="pencil" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">{{ $editing ? 'Edit Class' : 'Create Class' }}</h2>
            </div>

            @if ($editing)
                <form method="POST" action="{{ route('admin.students-results.academics.classes.names.update', $editing) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <x-field name="class_name" label="Name" required :value="$editing->name" />

                    <p class="hint">
                        Its classes are renamed with it —
                        {{ $classes->get($editing->id)?->pluck('name')->implode(', ') ?: 'it has none yet' }}.
                    </p>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a href="{{ route('admin.students-results.academics.classes') }}" class="text-xs text-muted hover:text-ink-soft">Cancel</a>

                        <button type="submit" class="btn-secondary btn-sm">
                            <x-nav-icon name="check" class="h-3.5 w-3.5" />
                            Update
                        </button>
                    </div>
                </form>
            @else
                <form method="POST" action="{{ route('admin.students-results.academics.classes.names.store') }}" class="space-y-4">
                    @csrf

                    <x-field name="class_name" label="Name" placeholder="JSS1" required />

                    @if ($sections->isEmpty())
                        <div>
                            <label class="label">Section <span class="text-rose-500">*</span></label>
                            <div class="input text-muted">No sections yet</div>
                            <p class="hint">
                                Create one on the <strong>Section</strong> tab first — a class is a class name
                                and a section put together.
                            </p>
                        </div>
                    @else
                        <x-field name="section_id" label="Section" type="select" required
                                 :placeholder-option="false"
                                 :options="$sections->pluck('name', 'id')->all()" />

                        <p class="hint">
                            Type the class name and pick the section: JSS1 and A make JSS1A. Pick another
                            section later to add JSS1B.
                        </p>
                    @endif

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="btn-secondary btn-sm">
                            <x-nav-icon name="check" class="h-3.5 w-3.5" />
                            Save
                        </button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Class List --}}
        <div class="card self-start p-5 lg:col-span-8">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="list" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">Class List</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-line text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="w-12 border-b border-r border-line p-3 text-center">#</th>
                            <th class="border-b border-r border-line p-3">Class Name</th>
                            <th class="border-b border-r border-line p-3">Section</th>
                            <th class="w-32 border-b border-line p-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink-soft">
                        @forelse ($levels as $level)
                            <tr class="transition-colors hover:bg-surface-3/60">
                                <td class="border-r border-line p-3 text-center align-top">{{ $loop->iteration }}</td>

                                <td class="border-r border-line p-3 align-top font-medium text-ink">{{ $level->name }}</td>

                                <td class="border-r border-line p-3 align-top leading-relaxed">
                                    @forelse ($classes->get($level->id) ?? collect() as $class)
                                        <span class="flex items-center gap-1">
                                            {{ $class->section?->name }}

                                            <form method="POST"
                                                  action="{{ route('admin.students-results.academics.classes.destroy-class', $class) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="rounded px-1 text-xs leading-none text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                                        title="Delete {{ $class->name }}">&times;</button>
                                            </form>
                                        </span>
                                    @empty
                                        <span class="text-xs text-muted">None yet</span>
                                    @endforelse
                                </td>

                                <td class="p-3 align-top">
                                    <div class="flex justify-center gap-2">
                                        <a href="{{ route('admin.students-results.academics.classes', ['edit' => $level->id]) }}"
                                           class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                           title="Edit {{ $level->name }}">
                                            <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                        </a>

                                        <form method="POST"
                                              action="{{ route('admin.students-results.academics.classes.names.destroy', $level) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-500 text-white transition hover:bg-rose-600"
                                                    title="Delete {{ $level->name }}">
                                                <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-8 text-center text-sm text-muted">
                                    No classes yet. Create one on the left.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ================= Section ================= --}}
    <div x-show="tab === 'section'" x-cloak class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">

        <div class="card self-start p-5 lg:col-span-4">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="cog" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">Create Section</h2>
            </div>

            <form method="POST" action="{{ route('admin.students-results.academics.classes.sections.store') }}" class="space-y-4">
                @csrf

                <x-field name="section_name" label="Name" placeholder="A" required
                         hint="A letter, or a word if the school divides its classes that way." />

                <div class="flex justify-end pt-2">
                    <button type="submit" class="btn-secondary btn-sm">
                        <x-nav-icon name="check" class="h-3.5 w-3.5" />
                        Save
                    </button>
                </div>
            </form>
        </div>

        <div class="card self-start p-5 lg:col-span-8">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="list" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">Section List</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-line text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="w-12 border-b border-r border-line p-3 text-center">#</th>
                            <th class="border-b border-r border-line p-3">Section</th>
                            <th class="border-b border-r border-line p-3">Classes</th>
                            <th class="w-32 border-b border-line p-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink-soft">
                        @forelse ($sections as $section)
                            <tr class="transition-colors hover:bg-surface-3/60">
                                <td class="border-r border-line p-3 text-center align-top">{{ $loop->iteration }}</td>
                                <td class="border-r border-line p-3 align-top font-medium text-ink">{{ $section->name }}</td>
                                <td class="border-r border-line p-3 align-top">
                                    {{ $section->schoolClasses->isEmpty() ? 'Not used by any class yet.' : $section->schoolClasses->pluck('name')->implode(', ') }}
                                </td>
                                <td class="p-3 align-top">
                                    <div class="flex justify-center">
                                        <form method="POST"
                                              action="{{ route('admin.students-results.academics.classes.sections.destroy', $section) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-500 text-white transition hover:bg-rose-600"
                                                    title="Delete section {{ $section->name }}">
                                                <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-8 text-center text-sm text-muted">
                                    No sections yet. Create one on the left.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
