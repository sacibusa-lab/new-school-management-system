@extends('layouts.admin')

@section('title', 'Classes & Sections')

@section('content')

@php
    // What the Edit tab's tag box needs: every section, and the ones this class has.
    $allSections = $sections->map(fn ($section) => ['id' => $section->id, 'name' => $section->name])->values();
    $chosen = $editing ? ($classes->get($editing->id)?->pluck('section_id')->all() ?? []) : [];
@endphp

{{--
    One card, with the tabs across its top: a class is a class name and a section put
    together, and the sections have to exist first, so the Section tab is the one that
    answers "there is nothing in the dropdown".
--}}
<div class="card overflow-hidden" x-data="{ tab: '{{ $tab }}' }">

    {{-- ================= Tabs ================= --}}
    <div class="border-b border-line px-6 pt-4">
        <div class="flex gap-8">
            @php
                $tabs = [
                    ['class', 'Class', 'academic'],
                    ['section', 'Section', 'cog'],
                    // A class is a class name and a section; a form teacher is put in
                    // charge of one of those classes. Its own tab, because it is per
                    // class rather than per class name: JSS1A and JSS1B have one each.
                    ['teacher', 'Form Teacher', 'briefcase'],
                ];

                if ($editing) {
                    $tabs[] = ['edit', 'Edit Class', 'pencil'];
                }
            @endphp

            @foreach ($tabs as [$key, $label, $icon])
                <button type="button"
                        @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}'
                            ? 'border-gold-400 text-ink'
                            : 'border-transparent text-muted hover:text-ink-soft'"
                        class="relative flex items-center gap-2 border-b-2 pb-3 text-sm font-medium transition-colors">
                    <x-nav-icon :name="$icon" class="h-4 w-4" />
                    <span>{{ $label }}</span>

                    @if ($key === 'edit')
                        <span class="absolute -bottom-1.5 left-1/2 h-2 w-2 -translate-x-1/2 rounded-full bg-gold-400"></span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    {{-- ================= Class ================= --}}
    <div x-show="tab === 'class'" class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-12">

        <div class="self-start rounded-xl border border-line p-5 lg:col-span-4">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="pencil" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">Create Class</h2>
            </div>

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
        </div>

        <div class="self-start rounded-xl border border-line p-5 lg:col-span-8">
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
    <div x-show="tab === 'section'" x-cloak class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-12">

        <div class="self-start rounded-xl border border-line p-5 lg:col-span-4">
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

        <div class="self-start rounded-xl border border-line p-5 lg:col-span-8">
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

    {{-- ================= Form Teacher ================= --}}
    <div x-show="tab === 'teacher'" x-cloak class="p-6">
        <div class="rounded-xl border border-line p-5">
            <div class="mb-5 flex items-center gap-2 border-b border-line-soft pb-3">
                <x-nav-icon name="briefcase" class="h-4 w-4 text-ink-soft" />
                <h2 class="text-base font-semibold text-ink">Form Teacher</h2>
            </div>

            @if ($teachers->isEmpty())
                <div class="rounded-xl bg-surface-2 p-6 text-center">
                    <x-nav-icon name="briefcase" class="mx-auto h-6 w-6 text-muted" />

                    <p class="mt-3 text-sm font-medium text-ink">No teachers yet</p>

                    <p class="mx-auto mt-1 max-w-md text-sm text-muted">
                        A class can only be given a form teacher once somebody is on the teaching
                        staff, and nobody is yet. Add the first teacher and this fills up.
                    </p>

                    <a href="{{ route('admin.students-results.teachers.create') }}"
                       class="btn-secondary btn-sm mt-4">
                        <x-nav-icon name="user-plus" class="h-3.5 w-3.5" />
                        Add a teacher
                    </a>
                </div>
            @else
                <p class="mb-4 text-sm text-muted">
                    One row per class. Who is in charge of JSS1A has nothing to do with JSS1B, so
                    each class keeps its own — and a class between teachers is left empty rather
                    than keeping a name nobody chose again.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-line text-sm">
                        <thead>
                            <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                                <th class="w-12 border-b border-r border-line p-3 text-center">#</th>
                                <th class="w-48 border-b border-r border-line p-3">Class</th>
                                <th class="border-b border-r border-line p-3">Form Teacher</th>
                                <th class="w-32 border-b border-line p-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line text-ink-soft">
                            @foreach ($allClasses as $class)
                                <tr class="transition-colors hover:bg-surface-3/60">
                                    <td class="border-r border-line p-3 text-center align-middle">{{ $loop->iteration }}</td>

                                    <td class="border-r border-line p-3 align-middle font-medium text-ink">
                                        {{ $class->name }}
                                    </td>

                                    <td class="border-r border-line p-3 align-middle">
                                        <form method="POST" id="form-teacher-{{ $class->id }}"
                                              action="{{ route('admin.students-results.academics.classes.teacher.update', $class) }}"
                                              class="max-w-md">
                                            @csrf
                                            @method('PUT')

                                            <select name="form_teacher_id"
                                                    aria-label="Form teacher of {{ $class->name }}"
                                                    class="input">
                                                <option value="">No form teacher</option>

                                                @foreach ($teachers as $teacher)
                                                    <option value="{{ $teacher->id }}" @selected($class->form_teacher_id === $teacher->id)>
                                                        {{ $teacher->name }}@if (! $teacher->is_active) — inactive @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                        </form>
                                    </td>

                                    <td class="p-3 align-middle">
                                        <div class="flex justify-center">
                                            <button type="submit" form="form-teacher-{{ $class->id }}"
                                                    class="btn-secondary btn-sm"
                                                    title="Save the form teacher of {{ $class->name }}">
                                                <x-nav-icon name="check" class="h-3.5 w-3.5" />
                                                Save
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ================= Edit Class ================= --}}
    @if ($editing)
        <div x-show="tab === 'edit'" class="px-6 py-12">
            <form id="edit-class" method="POST"
                  action="{{ route('admin.students-results.academics.classes.names.update', $editing) }}"
                  class="mx-auto max-w-2xl space-y-8"
                  x-data="{
                      all: {{ Js::from($allSections) }},
                      chosen: [],
                      init() {
                          const current = {{ Js::from($chosen) }};
                          this.chosen = this.all.filter(section => current.includes(section.id));
                      },
                      get spare() {
                          return this.all.filter(section => ! this.chosen.some(tag => tag.id === section.id));
                      },
                      take(event) {
                          const id = parseInt(event.target.value, 10);
                          if (id) { this.chosen.push(this.all.find(section => section.id === id)); }
                          event.target.value = '';
                      },
                      drop(id) { this.chosen = this.chosen.filter(tag => tag.id !== id); },
                  }">
                @csrf
                @method('PUT')

                {{-- Name --}}
                <div class="flex items-center">
                    <label for="class_name" class="w-1/4 pr-6 text-right text-sm font-medium text-ink-soft">
                        Name <span class="text-rose-500">*</span>
                    </label>

                    <div class="w-3/4">
                        <input id="class_name" name="class_name" type="text" required
                               value="{{ old('class_name', $editing->name) }}"
                               class="input @error('class_name') input-error @enderror">

                        @error('class_name')
                            <p class="error-text">{{ $message }}</p>
                        @enderror

                        <p class="hint">Its classes are renamed with it.</p>
                    </div>
                </div>

                {{-- Section --}}
                <div class="flex items-center">
                    <label for="section-picker" class="w-1/4 pr-6 text-right text-sm font-medium text-ink-soft">Section</label>

                    <div class="w-3/4">
                        <div class="flex min-h-[42px] w-full flex-wrap items-center gap-1.5 rounded-xl border border-line bg-surface p-1.5">
                            <template x-for="tag in chosen" :key="tag.id">
                                <span class="inline-flex items-center rounded-lg border border-line bg-surface-2 px-2 py-0.5 text-xs font-medium text-ink-soft">
                                    <button type="button" @click="drop(tag.id)"
                                            class="mr-1 text-muted transition hover:text-rose-600"
                                            :title="'Remove ' + tag.name">&times;</button>
                                    <span x-text="tag.name"></span>
                                    <input type="hidden" name="sections[]" :value="tag.id">
                                </span>
                            </template>

                            <select id="section-picker" @change="take($event)"
                                    class="rounded-lg border-0 bg-transparent py-1 pr-6 pl-1 text-xs text-muted focus:ring-0">
                                <option value="">+ add a section</option>
                                <template x-for="section in spare" :key="section.id">
                                    <option :value="section.id" x-text="section.name"></option>
                                </template>
                            </select>
                        </div>

                        <p class="hint">
                            Removing a section deletes that class — JSS1E — if nothing is written
                            against it yet.
                        </p>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex items-center justify-center gap-4 rounded-b-2xl border-t border-line-soft bg-surface-2 py-4">
            <button type="submit" form="edit-class" class="btn-secondary btn-sm">
                <x-nav-icon name="check" class="h-3.5 w-3.5" />
                Update
            </button>

            <a href="{{ route('admin.students-results.academics.classes') }}" class="text-xs text-muted hover:text-ink-soft">
                Cancel
            </a>
        </div>
    @endif
</div>

@endsection
