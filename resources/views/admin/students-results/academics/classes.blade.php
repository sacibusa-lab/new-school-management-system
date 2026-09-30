@extends('layouts.admin')

@section('title', 'Classes & Sections')

@section('content')

{{--
    Two tabs, one screen each: a class is a name and a section put together, and the
    sections have to exist before a class can be made from one — so the Section tab
    is the one that answers "there is nothing in the dropdown".
--}}
<div x-data="{ tab: '{{ $tab }}' }">

    {{-- ================= Tabs ================= --}}
    <div class="flex items-center gap-1 border-b border-line">
        @foreach ([['class', 'Class'], ['section', 'Section']] as [$key, $label])
            <button type="button"
                    @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}'
                        ? 'border-brand-600 text-ink'
                        : 'border-transparent text-muted hover:text-ink-soft'"
                    class="border-b-2 px-4 py-2.5 text-sm font-medium transition-colors">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ================= Class ================= --}}
    <div x-show="tab === 'class'" class="mt-6 grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">

        {{-- Create, or edit the row the pencil was clicked on --}}
        <div class="card-pad self-start">
            @if ($editing)
                <div class="flex items-start justify-between gap-3">
                    <p class="font-display text-base font-semibold text-ink">Edit Class</p>
                    <a href="{{ route('admin.students-results.academics.classes') }}" class="text-xs text-muted hover:text-ink-soft">Cancel</a>
                </div>

                <form method="POST" action="{{ route('admin.students-results.academics.classes.names.update', $editing) }}" class="mt-4">
                    @csrf
                    @method('PUT')

                    <x-field name="class_name" label="Name" required :value="$editing->name" />

                    <p class="hint">
                        Its classes are renamed with it —
                        {{ $classes->get($editing->id)?->pluck('name')->implode(', ') ?: 'it has none yet' }}.
                    </p>

                    <button type="submit" class="btn-primary btn-sm mt-4">Update</button>
                </form>
            @else
                <p class="font-display text-base font-semibold text-ink">Create Class</p>

                <form method="POST" action="{{ route('admin.students-results.academics.classes.names.store') }}" class="mt-4">
                    @csrf

                    <x-field name="class_name" label="Name" placeholder="JSS1" required
                             hint="The class the school runs, without a section." />

                    <x-field name="order" label="Position" type="number" min="1" max="255" class="mt-4"
                             :value="$levels->max('order') + 1"
                             hint="Where it sits in lists." />

                    <button type="submit" class="btn-primary btn-sm mt-4">Save</button>
                </form>

                <p class="mt-4 rounded-xl bg-surface-2 px-3.5 py-3 text-xs text-ink-soft ring-1 ring-line">
                    Save it first, then give it sections from the list — that is what turns JSS1 into
                    JSS1A and JSS1B.
                </p>
            @endif
        </div>

        {{-- Class List --}}
        <div class="table-wrap self-start">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-12">#</th>
                        <th>Class Name</th>
                        <th>Section</th>
                        <th class="w-28 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($levels as $level)
                        @php
                            $ofLevel = $classes->get($level->id) ?? collect();
                            $unused = $sections->whereNotIn('id', $ofLevel->pluck('section_id')->all());
                        @endphp

                        <tr>
                            <td class="text-muted">{{ $loop->iteration }}</td>

                            <td class="font-medium text-ink">{{ $level->name }}</td>

                            <td>
                                @if ($ofLevel->isEmpty())
                                    <span class="text-xs text-muted">No sections yet — add one to make this a class.</span>
                                @else
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($ofLevel as $class)
                                            <span class="inline-flex items-center gap-1 rounded-lg bg-surface-3 py-0.5 pl-2.5 pr-0.5 text-xs font-medium text-ink-soft ring-1 ring-line"
                                                  title="{{ $class->name }}{{ $class->students_count > 0 ? ' — ' . $class->students_count . ' student(s)' : '' }}">
                                                {{ $class->section?->name }}

                                                <form method="POST"
                                                      action="{{ route('admin.students-results.academics.classes.destroy-class', $class) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="rounded-md px-1 text-sm leading-none text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                                            title="Delete {{ $class->name }}">&times;</button>
                                                </form>
                                            </span>
                                        @endforeach

                                        @if ($unused->isNotEmpty())
                                            <form method="POST"
                                                  action="{{ route('admin.students-results.academics.classes.store-class', $level) }}"
                                                  class="flex items-center gap-1.5">
                                                @csrf

                                                <label class="sr-only" for="section-{{ $level->id }}">Add a section to {{ $level->name }}</label>
                                                <select id="section-{{ $level->id }}" name="section_id"
                                                        class="rounded-lg border border-line bg-surface py-1 pl-2 pr-6 text-xs text-ink-soft"
                                                        onchange="this.form.requestSubmit()">
                                                    <option value="">+ add</option>
                                                    @foreach ($unused as $section)
                                                        <option value="{{ $section->id }}">{{ $section->name }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </td>

                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="{{ route('admin.students-results.academics.classes', ['edit' => $level->id]) }}"
                                       class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-surface-3 text-ink-soft transition hover:text-ink"
                                       title="Edit {{ $level->name }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>
                                        </svg>
                                    </a>

                                    <form method="POST"
                                          action="{{ route('admin.students-results.academics.classes.names.destroy', $level) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-600 text-white transition hover:bg-rose-700"
                                                title="Delete {{ $level->name }}">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-10 text-center text-sm text-muted">
                                No classes yet. Create one on the left.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ================= Section ================= --}}
    <div x-show="tab === 'section'" x-cloak class="mt-6 grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">

        <div class="card-pad self-start">
            <p class="font-display text-base font-semibold text-ink">Create Section</p>

            <form method="POST" action="{{ route('admin.students-results.academics.classes.sections.store') }}" class="mt-4">
                @csrf

                <x-field name="section_name" label="Name" placeholder="A" required
                         hint="A letter, or a word if the school divides its classes that way." />

                <button type="submit" class="btn-primary btn-sm mt-4">Save</button>
            </form>

            <p class="mt-4 rounded-xl bg-surface-2 px-3.5 py-3 text-xs text-ink-soft ring-1 ring-line">
                Sections are made first. A class is then its name and one of these put together,
                which is what makes JSS1A different from JSS1.
            </p>
        </div>

        <div class="table-wrap self-start">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-12">#</th>
                        <th>Section</th>
                        <th>Classes</th>
                        <th class="w-28 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sections as $section)
                        <tr>
                            <td class="text-muted">{{ $loop->iteration }}</td>
                            <td class="font-medium text-ink">{{ $section->name }}</td>
                            <td class="text-sm">
                                {{ $section->schoolClasses->isEmpty() ? 'Not used by any class yet.' : $section->schoolClasses->pluck('name')->implode(', ') }}
                            </td>
                            <td>
                                <div class="flex items-center justify-end">
                                    <form method="POST"
                                          action="{{ route('admin.students-results.academics.classes.sections.destroy', $section) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-600 text-white transition hover:bg-rose-700"
                                                title="Delete section {{ $section->name }}">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-10 text-center text-sm text-muted">
                                No sections yet. Create one on the left.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection
