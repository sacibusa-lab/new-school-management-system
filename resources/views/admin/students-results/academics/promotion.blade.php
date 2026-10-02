@extends('layouts.admin')

@section('title', 'Promotion')

@section('content')
    <div class="space-y-4">
        {{-- Page header: the house + title, and the year being closed. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <x-nav-icon name="home" class="h-5 w-5 text-gold-500" />
                <h1 class="text-lg font-semibold text-ink">Promotion</h1>
            </div>

            @if ($fromSession && $toSession)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gold-100 px-2.5 py-1 text-xs font-medium text-gold-800 dark:bg-gold-900/40 dark:text-gold-200">
                    <x-nav-icon name="refresh" class="h-3.5 w-3.5" />
                    {{ $fromSession->name }} → {{ $toSession->name }}
                </span>
            @endif
        </div>

        @if ($sessions->isEmpty())
            <x-empty-state icon="refresh"
                           title="No academic sessions yet"
                           description="A promotion moves a class out of one session and into the next, so the two sessions have to exist first.">
                <a href="{{ route('admin.settings.index') }}" class="btn-primary btn-sm">Go to Settings</a>
            </x-empty-state>
        @elseif ($toSession === null)
            <x-empty-state icon="calendar"
                           title="There is no session to move into"
                           description="This is the last session on the calendar. Add the session that follows {{ $fromSession?->name }} and the class can be moved into it.">
                <a href="{{ route('admin.settings.index') }}" class="btn-primary btn-sm">Add the next session</a>
            </x-empty-state>
        @elseif ($classes->isEmpty())
            <x-empty-state icon="grid"
                           title="No classes to promote"
                           description="Promotion moves classes, so the school needs its classes and sections first.">
                <a href="{{ route('admin.students-results.academics.classes') }}" class="btn-primary btn-sm">Go to Classes &amp; Sections</a>
            </x-empty-state>
        @else
            <section class="overflow-hidden rounded-lg border border-line bg-surface shadow-card" aria-label="Class promotion">
                {{-- Which class, out of which session, into which. --}}
                <form method="GET" action="{{ route('admin.students-results.academics.promotion') }}"
                      class="flex flex-col gap-3 border-b border-line p-5 sm:flex-row sm:items-end">
                    <div>
                        <label class="label" for="promotion-from">Moving out of</label>
                        <select id="promotion-from" name="from" onchange="this.form.submit()" class="input w-44">
                            @foreach ($sessions as $session)
                                <option value="{{ $session->id }}" @selected($fromSession?->id === $session->id)>{{ $session->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="promotion-to">Into</label>
                        <select id="promotion-to" name="to" onchange="this.form.submit()" class="input w-44">
                            @foreach ($sessions as $session)
                                @continue($session->id === $fromSession?->id)
                                <option value="{{ $session->id }}" @selected($toSession->id === $session->id)>{{ $session->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="promotion-class">Class</label>
                        <select id="promotion-class" name="class" onchange="this.form.submit()" class="input w-56">
                            @foreach ($classes as $class)
                                <option value="{{ $class->id }}" @selected($selectedClass?->id === $class->id)>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn-secondary btn-sm h-10">Show</button>
                </form>

                @if ($students->isEmpty())
                    <div class="p-6">
                        <p class="text-sm text-muted">
                            Nobody is on the register for
                            <span class="font-medium text-ink">{{ $selectedClass->name }}</span>
                            in {{ $fromSession->name }}.
                        </p>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.students-results.academics.promotion.store') }}" class="p-5">
                        @csrf
                        <input type="hidden" name="school_class_id" value="{{ $selectedClass->id }}">
                        <input type="hidden" name="from_session_id" value="{{ $fromSession->id }}">
                        <input type="hidden" name="to_session_id" value="{{ $toSession->id }}">

                        <div class="mb-4">
                            <p class="text-sm text-muted">
                                <span class="font-medium text-ink">{{ $selectedClass->name }}</span>,
                                {{ $fromSession->name }} into {{ $toSession->name }}.
                                The average is this session's result — it is shown to inform the decision, not to make it.
                            </p>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-line">
                            <table class="w-full min-w-[820px] border-collapse text-left text-sm">
                                <thead>
                                    <tr class="border-b border-line bg-surface-3 text-xs font-semibold tracking-wider text-muted uppercase">
                                        <th scope="col" class="w-12 border-r border-line px-3 py-3 text-center">#</th>
                                        <th scope="col" class="border-r border-line px-4 py-3">Student</th>
                                        <th scope="col" class="w-24 border-r border-line px-4 py-3 text-center">Average</th>
                                        <th scope="col" class="w-48 border-r border-line px-4 py-3">Decision</th>
                                        <th scope="col" class="w-56 px-4 py-3">Move to</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line text-ink-soft">
                                    @foreach ($students as $student)
                                        @php
                                            $existing = $decisions->get($student->id);
                                            $action = $existing?->action?->value ?? ($defaultTarget ? 'promoted' : 'graduated');
                                            $target = $existing?->to_school_class_id ?? $defaultTarget?->id;
                                            $average = $averages[$student->id] ?? null;
                                        @endphp

                                        <tr x-data="{ action: @js($action) }" class="transition-colors hover:bg-surface-3/60">
                                            <td class="border-r border-line px-3 py-3 text-center align-top">{{ $loop->iteration }}</td>

                                            <td class="border-r border-line px-4 py-3 align-top">
                                                <span class="block font-medium text-ink">{{ $student->full_name }}</span>
                                                <span class="mt-0.5 block text-xs text-muted">{{ $student->student_number }}</span>

                                                {{-- The year already recorded: why somebody who has
                                                     moved on is still on this list, and what was said. --}}
                                                @if ($existing)
                                                    <span class="mt-1 inline-flex items-center gap-1.5 text-xs text-muted">
                                                        <span class="badge {{ $existing->action?->badge() }}">{{ $existing->action?->label() }}</span>
                                                        @if ($existing->toClass)
                                                            <span>to {{ $existing->toClass->name }}</span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </td>

                                            <td class="border-r border-line px-4 py-3 text-center align-top">
                                                {{ $average === null ? '—' : number_format($average, 2) }}
                                            </td>

                                            <td class="border-r border-line px-4 py-3 align-top">
                                                <label class="sr-only" for="action-{{ $student->id }}">Decision for {{ $student->full_name }}</label>
                                                <select id="action-{{ $student->id }}" name="decisions[{{ $student->id }}][action]"
                                                        x-model="action" class="input py-1.5 text-xs">
                                                    @foreach ($actions as $option)
                                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </td>

                                            <td class="px-4 py-3 align-top">
                                                <label class="sr-only" for="class-{{ $student->id }}">Class for {{ $student->full_name }}</label>
                                                <select id="class-{{ $student->id }}" name="decisions[{{ $student->id }}][class_id]"
                                                        :disabled="action !== 'promoted'"
                                                        class="input py-1.5 text-xs">
                                                    <option value="">Choose a class</option>
                                                    @foreach ($classesByLevel as $levelName => $levelClasses)
                                                        <optgroup label="{{ $levelName }}">
                                                            @foreach ($levelClasses as $class)
                                                                <option value="{{ $class->id }}" @selected((int) $target === $class->id)>{{ $class->name }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                            <p class="text-xs text-muted">
                                Their results, marks and bills are left exactly where they were earned — only where they are now changes.
                            </p>
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded bg-gold-500 px-5 py-2 text-sm font-medium text-white shadow-xs transition hover:bg-gold-600">
                                <x-nav-icon name="check" class="h-4 w-4" />
                                Save promotion
                            </button>
                        </div>
                    </form>
                @endif
            </section>
        @endif
    </div>
@endsection

