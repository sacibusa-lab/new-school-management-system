@extends('layouts.admin')

@section('title', 'Edit '.$student->full_name)
@section('subtitle', $student->student_number.' · '.$student->status->label())

@section('content')

@php
    // Every class the school runs, so moving a student between arms is one click.
    // Labelled with the class name — JSS1A — because that is what is written on the
    // register and what the office reads out loud.
    //
    // Built with a loop rather than `flatMap`: that collapses through `array_merge`,
    // which renumbers integer keys, and the option values would then be 0, 1, 2 …
    // instead of the class ids — so the wrong class would be picked off the list and
    // saved. The key is the id, and it has to stay the id.
    $classes = [];

    foreach ($levels as $level) {
        foreach ($level->classes as $class) {
            $classes[$class->id] = $class->name;
        }
    }
@endphp

<form method="POST" action="{{ route('admin.students.update', $student) }}" class="space-y-6">
    @csrf
    @method('PUT')

    {{-- ================= The person ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Student</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <x-field name="first_name" label="First name" required :value="$student->first_name" />
            <x-field name="middle_name" label="Middle name" :value="$student->middle_name" />
            <x-field name="last_name" label="Surname" required :value="$student->last_name" />

            <x-field name="gender" label="Gender" type="select"
                     placeholder-option="Not given"
                     :value="$student->gender?->value"
                     :options="\App\Enums\Gender::options()" />

            <x-field name="date_of_birth" label="Date of birth" type="date"
                     :value="$student->date_of_birth?->format('Y-m-d')" />

            {{-- No empty choice: a student is always one of these, and "Choose a status"
                 would be an option nobody may pick. --}}
            <x-field name="status" label="Status" type="select" required
                     :placeholder-option="false"
                     :value="$student->status->value"
                     :options="\App\Enums\StudentStatus::options()" />

            <x-field name="email" label="Email" type="email" :value="$student->email" />
            <x-field name="phone" label="Phone" :value="$student->phone" />

            <x-field name="address" label="Address" :value="$student->address" class="sm:col-span-3" />
        </div>
    </div>

    {{-- ================= Where they sit ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Class</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <x-field name="school_class_id" label="Class" type="select"
                     placeholder-option="Not placed in a class yet"
                     hint="A student in JSS2A is in JSS2, so the year group follows the class."
                     :value="$student->school_class_id"
                     :options="$classes" />

            {{-- Offered for the child who has no class yet: admitted, waiting to be put
                 in an arm. Where a class is named it decides the year group, so this is
                 only read when there is no class to read it from. --}}
            <x-field name="level_id" label="Year group" type="select"
                     placeholder-option="No year group"
                     hint="Only used while there is no class to take it from."
                     :value="$student->level_id"
                     :options="$levels->pluck('name', 'id')->all()" />
        </div>
    </div>

    {{-- ================= The parent ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Parent / guardian</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <x-field name="guardian_name" label="Name" :value="$student->guardian_name" />
            <x-field name="guardian_phone" label="Phone" :value="$student->guardian_phone" />
            <x-field name="guardian_email" label="Email" type="email" :value="$student->guardian_email" />
        </div>
    </div>

    {{-- ================= What the family can reach ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Portal access</h2>
        </div>

        <div class="space-y-4 p-5">
            @foreach ([
                ['results_portal_enabled', 'Results portal', $student->results_portal_enabled,
                    'Whether the family can look this student’s results up.'],
                ['fees_portal_enabled', 'Fees portal', $student->fees_portal_enabled,
                    'Whether the family can see what has been billed and what has been paid.'],
            ] as [$name, $label, $checked, $hint])
                <label class="flex items-start gap-3">
                    <input type="checkbox"
                           name="{{ $name }}"
                           value="1"
                           @checked(old($name, $checked))
                           class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300">

                    <span>
                        <span class="block text-sm font-medium text-ink">{{ $label }}</span>
                        <span class="block text-xs text-muted">{{ $hint }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary">Save changes</button>
        <a href="{{ route('admin.students.show', $student) }}" class="btn-ghost">Cancel</a>
    </div>
</form>

@endsection
