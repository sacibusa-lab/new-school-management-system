@extends('layouts.admin')

@section('title', 'Class Assign')

@section('content')
    <div class="space-y-4">
        {{-- Page header: the reference's house + title, with the session this register belongs to. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <x-nav-icon name="home" class="h-5 w-5 text-gold-500" />
                <h1 class="text-lg font-semibold text-ink">Class Assign</h1>
            </div>

            <span class="inline-flex items-center gap-1.5 rounded-full bg-gold-100 px-2.5 py-1 text-xs font-medium text-gold-800 dark:bg-gold-900/40 dark:text-gold-200">
                <x-nav-icon name="calendar" class="h-3.5 w-3.5" />
                {{ $academicSession ? 'Academic Year '.$academicSession->name : 'No active session' }}
            </span>
        </div>

        <section class="overflow-hidden rounded-lg border border-line bg-surface shadow-card" aria-label="Class subject assignments">
            {{-- Tabs: amber underline on the active one, exactly as the reference draws them. --}}
            <div class="flex space-x-6 border-b border-line px-6 pt-4">
                @php
                    $tabs = [
                        ['list', 'Assign List', 'list'],
                        ['assign', 'Assign', 'pencil'],
                    ];
                @endphp

                @foreach ($tabs as [$key, $label, $icon])
                    <a href="{{ route('admin.students-results.academics.subjects.assignments', ['tab' => $key]) }}"
                       @if ($tab === $key) aria-current="page" @endif
                       @class([
                           'flex items-center gap-2 border-b-2 pb-3 text-sm font-medium transition-colors',
                           'border-gold-400 text-gold-700 dark:text-gold-300' => $tab === $key,
                           'border-transparent text-muted hover:text-ink-soft' => $tab !== $key,
                       ])>
                        <x-nav-icon :name="$icon" class="h-4 w-4" />
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>

            @if ($tab === 'assign')
                <div class="p-6">
                    <form method="POST"
                          action="{{ $editing ? route('admin.students-results.academics.subjects.assignments.update', $editing) : route('admin.students-results.academics.subjects.assignments.store') }}"
                          x-data="{
                              levelId: @js($selectedLevelId),
                              sectionId: @js($selectedSectionId),
                              sectionsByLevel: @js($sectionsByLevel),
                              selectedSubjects: @js($selectedSubjectIds),
                              teacherAssignments: @js((object) $selectedTeacherIds),
                          }"
                          class="max-w-4xl space-y-6">
                        @csrf
                        @if ($editing)
                            @method('PUT')
                        @endif

                        <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-ink">
                                    {{ $editing ? 'Edit Class Assignment' : 'Assign Subjects to Class' }}
                                </h2>
                                <p class="mt-0.5 text-xs text-muted">Select a class and section, then tick every subject it sits.</p>
                            </div>

                            <a href="{{ route('admin.students-results.academics.subjects.assignments', ['tab' => 'list']) }}"
                               class="shrink-0 text-xs text-muted transition hover:text-ink-soft">
                                Cancel
                            </a>
                        </div>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-ink-soft" for="assignment-class">
                                    Class <span class="text-rose-500">*</span>
                                </label>
                                <select id="assignment-class" name="level_id" x-model="levelId" @change="sectionId = ''"
                                        class="input" required @disabled($editing)>
                                    <option value="">Select a class</option>
                                    @foreach ($levels as $level)
                                        <option value="{{ $level->id }}">{{ $level->name }}</option>
                                    @endforeach
                                </select>
                                @if ($editing)
                                    <input type="hidden" name="level_id" value="{{ $editing->level_id }}">
                                @endif
                                @error('level_id')
                                    <p class="error-text">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-ink-soft" for="assignment-section">
                                    Section <span class="text-rose-500">*</span>
                                </label>
                                <select id="assignment-section" name="section_id" x-model="sectionId" :disabled="! levelId"
                                        class="input" required @disabled($editing)>
                                    <option value="">Select class first</option>
                                    <template x-for="section in (sectionsByLevel[levelId] || [])" :key="section.id">
                                        <option :value="section.id" x-text="section.name"></option>
                                    </template>
                                </select>
                                @if ($editing)
                                    <input type="hidden" name="section_id" value="{{ $editing->section_id }}">
                                @endif
                                @error('section_id')
                                    <p class="error-text">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <label class="block text-xs font-semibold uppercase tracking-wider text-ink-soft" for="assignment-subjects">
                                    Subjects <span class="text-rose-500">*</span>
                                </label>

                                <div class="flex items-center gap-2 text-xs">
                                    <span class="text-muted" x-text="selectedSubjects.length + ' selected'"></span>
                                    <span class="text-line">|</span>
                                    <button type="button" class="text-gold-600 transition hover:underline dark:text-gold-400"
                                            @click="selectedSubjects = @js($subjects->pluck('id')->map(fn ($id) => (string) $id)->values())">
                                        Select All
                                    </button>
                                    <span class="text-line">|</span>
                                    <button type="button" class="text-muted transition hover:underline" @click="selectedSubjects = []">
                                        Clear All
                                    </button>
                                </div>
                            </div>

                            <div id="assignment-subjects"
                                 class="grid max-h-72 grid-cols-1 gap-3 overflow-y-auto rounded-lg border border-line bg-surface-2 p-4 sm:grid-cols-2 md:grid-cols-3">
                                @forelse ($subjects as $subject)
                                    <div class="flex flex-col gap-2 rounded border border-line bg-surface p-2 transition hover:border-gold-400">
                                        <label class="flex cursor-pointer items-center gap-2.5 text-xs font-medium text-ink-soft">
                                            <input type="checkbox" name="subject_ids[]" value="{{ $subject->id }}"
                                                   x-model="selectedSubjects"
                                                   @checked(in_array((string) $subject->id, $selectedSubjectIds, true))
                                                   class="h-4 w-4 rounded border-line text-gold-500 focus:ring-gold-400">
                                            <span class="truncate text-ink">{{ $subject->name }}</span>
                                        </label>

                                        <select name="teachers[{{ $subject->id }}]"
                                                x-model="teacherAssignments['{{ $subject->id }}']"
                                                :disabled="! selectedSubjects.includes('{{ $subject->id }}')"
                                                aria-label="Teacher for {{ $subject->name }}"
                                                class="input py-1 text-xs">
                                            <option value="">No teacher assigned</option>
                                            @foreach ($teachers as $teacher)
                                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @empty
                                    <p class="col-span-full p-3 text-sm text-muted">There are no active subjects to assign.</p>
                                @endforelse
                            </div>

                            @error('subject_ids')
                                <p class="error-text">{{ $message }}</p>
                            @enderror
                            @error('teachers')
                                <p class="error-text">{{ $message }}</p>
                            @enderror

                            @if ($inactiveSubjects->isNotEmpty())
                                <p class="hint">
                                    Inactive subjects already assigned to this class stay linked until they are
                                    reactivated or the assignment is removed.
                                </p>
                            @endif
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-line pt-4">
                            <a href="{{ route('admin.students-results.academics.subjects.assignments', ['tab' => 'list']) }}"
                               class="rounded border border-line px-4 py-2 text-sm text-ink-soft transition hover:bg-surface-2">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded bg-gold-500 px-5 py-2 text-sm font-medium text-white shadow-xs transition hover:bg-gold-600">
                                <x-nav-icon name="check" class="h-4 w-4" />
                                {{ $editing ? 'Update Assignment' : 'Save Assignment' }}
                            </button>
                        </div>
                    </form>
                </div>
            @else
                <div class="p-6"
                     x-data="{
                         search: '',
                         sortCol: null,
                         sortAsc: true,
                         rows() {
                             return Array.from(this.$refs.tbody.querySelectorAll('tr.assignment-row'));
                         },
                         sortBy(index) {
                             const asc = this.sortCol === index ? ! this.sortAsc : true;
                             this.sortCol = index;
                             this.sortAsc = asc;
                             this.rows().sort((a, b) => {
                                 const av = a.children[index].innerText.trim().toLowerCase();
                                 const bv = b.children[index].innerText.trim().toLowerCase();
                                 return av.localeCompare(bv, undefined, { numeric: true }) * (asc ? 1 : -1);
                             }).forEach(tr => this.$refs.tbody.appendChild(tr));
                         },
                     }">
                    {{-- Search sits on its own, right-aligned above the table. --}}
                    <div class="mb-6 flex justify-end">
                        <div class="relative w-full md:w-64">
                            <input type="search" x-model="search" placeholder="Search..." aria-label="Search assignments"
                                   class="w-full rounded border border-line bg-surface py-1.5 pr-8 pl-3 text-sm text-ink transition placeholder:text-muted focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 focus:outline-none">
                            <span class="pointer-events-none absolute top-2.5 right-2.5 text-muted">
                                <x-nav-icon name="search" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </div>

                    {{-- Table: sortable headers, a divider between each column, dash-bulleted
                         subjects and centred row actions. --}}
                    <div class="overflow-x-auto rounded-lg border border-line">
                        <table class="w-full min-w-[700px] border-collapse text-left" id="assignments-table">
                            <thead>
                                <tr class="border-b border-line bg-surface-3 text-xs font-semibold tracking-wider text-muted uppercase">
                                    <th scope="col" @click="sortBy(0)"
                                        class="w-16 cursor-pointer border-r border-line px-4 py-3 transition hover:bg-line">
                                        <div class="flex items-center justify-between gap-1">
                                            <span>Sl</span>
                                            <x-nav-icon name="sort" class="h-3 w-3 text-muted" />
                                        </div>
                                    </th>
                                    <th scope="col" @click="sortBy(1)"
                                        class="w-28 cursor-pointer border-r border-line px-4 py-3 transition hover:bg-line">
                                        <div class="flex items-center justify-between gap-1">
                                            <span>Class</span>
                                            <x-nav-icon name="sort" class="h-3 w-3 text-muted" />
                                        </div>
                                    </th>
                                    <th scope="col" @click="sortBy(2)"
                                        class="w-28 cursor-pointer border-r border-line px-4 py-3 transition hover:bg-line">
                                        <div class="flex items-center justify-between gap-1">
                                            <span>Section</span>
                                            <x-nav-icon name="sort" class="h-3 w-3 text-muted" />
                                        </div>
                                    </th>
                                    <th scope="col" @click="sortBy(3)"
                                        class="cursor-pointer border-r border-line px-4 py-3 transition hover:bg-line">
                                        <div class="flex items-center justify-between gap-1">
                                            <span>Subject</span>
                                            <x-nav-icon name="sort" class="h-3 w-3 text-muted" />
                                        </div>
                                    </th>
                                    <th scope="col" @click="sortBy(4)"
                                        class="w-40 cursor-pointer border-r border-line px-4 py-3 transition hover:bg-line">
                                        <div class="flex items-center justify-between gap-1">
                                            <span>Teacher</span>
                                            <x-nav-icon name="sort" class="h-3 w-3 text-muted" />
                                        </div>
                                    </th>
                                    <th scope="col" class="w-28 px-4 py-3 text-center">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody x-ref="tbody" class="divide-y divide-line bg-surface text-sm text-ink-soft">
                                @forelse ($assignments as $schoolClass)
                                    @php
                                        $sl = $assignments->firstItem() + $loop->index;
                                        $searchData = strtolower(implode(' ', [
                                            $sl,
                                            $schoolClass->level?->name ?? '',
                                            $schoolClass->section?->name ?? '',
                                            $schoolClass->subjects->pluck('name')->implode(' '),
                                            $schoolClass->subjects
                                                ->map(fn ($subject) => $subject->pivot->teacher_id ? ($teacherNames[$subject->pivot->teacher_id] ?? '') : '')
                                                ->implode(' '),
                                        ]));
                                    @endphp

                                    <tr class="assignment-row transition-colors hover:bg-gold-50/40 dark:hover:bg-surface-3/50"
                                        x-show="! search || {{ Js::from($searchData) }}.includes(search.toLowerCase())">
                                        <td class="border-r border-line px-4 py-4 align-top font-medium text-ink">{{ $sl }}</td>
                                        <td class="border-r border-line px-4 py-4 align-top">{{ $schoolClass->level?->name }}</td>
                                        <td class="border-r border-line px-4 py-4 align-top">{{ $schoolClass->section?->name }}</td>
                                        <td class="border-r border-line px-4 py-4 align-top text-xs leading-relaxed font-medium tracking-tight text-ink uppercase">
                                            <div class="divide-y divide-line-soft">
                                                @forelse ($schoolClass->subjects as $subject)
                                                    <div class="py-1.5 whitespace-nowrap first:pt-0 last:pb-0">- {{ $subject->name }}</div>
                                                @empty
                                                    <div class="py-1.5 text-sm normal-case text-muted first:pt-0 last:pb-0">No subjects yet</div>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td class="border-r border-line px-4 py-4 align-top text-xs leading-relaxed">
                                            <div class="divide-y divide-line-soft">
                                                @forelse ($schoolClass->subjects as $subject)
                                                    @php($teacherName = $subject->pivot->teacher_id ? ($teacherNames[$subject->pivot->teacher_id] ?? null) : null)
                                                    <div @class(['py-1.5 truncate first:pt-0 last:pb-0', 'text-muted' => $teacherName === null])>
                                                        {{ $teacherName ?? '—' }}
                                                    </div>
                                                @empty
                                                    <div class="py-1.5 text-muted first:pt-0 last:pb-0">—</div>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-center align-top">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="{{ route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign', 'edit' => $schoolClass->id]) }}"
                                                   title="Edit {{ $schoolClass->name }} subject assignments"
                                                   aria-label="Edit {{ $schoolClass->name }} subject assignments"
                                                   class="flex h-7 w-7 items-center justify-center rounded-full border border-line bg-surface text-ink-soft shadow-2xs transition hover:border-gold-400 hover:text-gold-600">
                                                    <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                                </a>

                                                <form method="POST"
                                                      action="{{ route('admin.students-results.academics.subjects.assignments.destroy', $schoolClass) }}"
                                                      onsubmit="return confirm('Remove this class’s subject assignments for the current session?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            title="Remove {{ $schoolClass->name }} subject assignments"
                                                            aria-label="Remove {{ $schoolClass->name }} subject assignments"
                                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-rose-600 text-white shadow-2xs transition hover:bg-rose-700">
                                                        <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-12 text-center text-muted">
                                            <x-nav-icon name="folder-open" class="mx-auto mb-2 h-9 w-9 text-muted" />
                                            <p>No matching class assignments found.</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($assignments->hasPages())
                        <div class="mt-4">{{ $assignments->links() }}</div>
                    @endif
                </div>
            @endif
        </section>
    </div>
@endsection
