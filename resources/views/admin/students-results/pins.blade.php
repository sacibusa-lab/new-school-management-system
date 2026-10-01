@extends('layouts.admin')

@section('title', 'Generate Pin')

@php
    // Each class and the sections it actually has, so the section list can grey out
    // the rest: JSS1C is not a class until somebody makes it one.
    $held = $levels->map(fn ($level) => [
        'id' => $level->id,
        'sections' => $level->classes->pluck('section_id')->values(),
    ])->values();
@endphp

@section('content')
    @if ($session === null || $term === null)
        <x-alert tone="warning" class="mb-5">
            There is no current session and term, so a PIN would have nothing to belong to.
            Set them on the
            <a href="{{ route('admin.settings.index') }}" class="underline underline-offset-2">Settings</a>
            page first.
        </x-alert>
    @endif

    <div class="card overflow-hidden"
         x-data="{
             level: '{{ old('level_id') }}',
             held: {{ Js::from($held) }},
             sectionsFor() {
                 const found = this.held.find(level => String(level.id) === String(this.level));

                 return found ? found.sections : [];
             },
         }">
        <div class="panel-header">
            <p class="panel-title">Select Ground</p>
        </div>

        <form method="POST" action="{{ route('admin.students-results.pins.generate') }}" class="card-pad">
            @csrf

            <div class="grid gap-5 sm:grid-cols-3 sm:items-end">
                <x-field name="level_id" label="Class" type="select" required
                         placeholder-option="First select the class"
                         :options="$levels->pluck('name', 'id')->all()"
                         x-on:change="level = $event.target.value" />

                <div>
                    <label for="section_id" class="label">
                        Section <span class="text-rose-500">*</span>
                    </label>

                    <select id="section_id" name="section_id" required
                            class="input @error('section_id') input-error @enderror">
                        <option value="">Select the class first</option>

                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}"
                                    @selected((string) old('section_id') === (string) $section->id)
                                    x-bind:disabled="level !== '' && ! sectionsFor().includes({{ $section->id }})">
                                {{ $section->name }}
                            </option>
                        @endforeach
                    </select>

                    @error('section_id')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <button type="submit" class="btn-primary w-full">Generate</button>
                </div>
            </div>

            <p class="hint mt-4">
                One PIN to a student for the term.
                @if ($term)
                    Generating now makes PINs for <strong>{{ $term->name }}</strong>{{ $session ? ', '.$session->name : '' }},
                    and leaves alone everybody who already has one.
                @endif
            </p>
        </form>
    </div>

    @if ($class !== null && $session !== null && $term !== null)
        <div class="card mt-6 overflow-hidden">
            <div class="panel-header">
                <div>
                    <p class="panel-title">PINs for {{ $class->name }}</p>
                    <p class="mt-0.5 text-xs text-muted">{{ $term->name }} · {{ $session->name }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge-neutral">
                        {{ $pins->count() }} of {{ $students->count() }} issued
                    </span>

                    <a href="{{ route('admin.students-results.pins.sheet', ['class' => $class->id]) }}"
                       class="btn-secondary btn-sm">
                        <x-nav-icon name="printer" class="h-3.5 w-3.5" />
                        Print the PINs
                    </a>
                </div>
            </div>

            @if ($students->isEmpty())
                <p class="card-pad text-sm text-muted">
                    No students in {{ $class->name }} yet. PINs are made for the students on roll, so
                    there is nothing to make one for.
                </p>
            @else
                <div class="table-wrap mt-0 rounded-none border-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-12">#</th>
                                <th>Student</th>
                                <th>Admission number</th>
                                <th>PIN</th>
                                <th>State</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($students as $student)
                                @php $pin = $pins[$student->id] ?? null; @endphp
                                <tr>
                                    <td class="text-muted">{{ $loop->iteration }}</td>

                                    <td>
                                        <p class="font-medium text-ink">{{ $student->full_name }}</p>
                                    </td>

                                    <td class="font-mono text-xs text-muted">
                                        {{ $student->student_number ?? '—' }}
                                    </td>

                                    <td class="font-mono tracking-wider text-ink-soft">
                                        {{ $pin?->grouped() ?? '—' }}
                                    </td>

                                    <td>
                                        @if ($pin === null)
                                            <span class="text-xs text-muted">No PIN yet</span>
                                        @elseif ($pin->isUsed())
                                            <span class="badge-neutral">Used</span>
                                        @else
                                            <span class="badge-neutral">Issued</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection
