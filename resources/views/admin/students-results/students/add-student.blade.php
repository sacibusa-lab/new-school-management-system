@extends('layouts.admin')

@section('title', 'Add Students')

@php
    // Which sections the school actually runs under each year group, so the section
    // dropdown can offer only the arms that exist: JSS1 and C are JSS1C, and a school
    // with no JSS1C has no JSS1C to put a child in.
    $classPairs = $levels->mapWithKeys(fn ($level) => [
        (string) $level->id => $sections
            ->filter(fn ($section) => $level->classes->contains('section_id', $section->id))
            ->mapWithKeys(fn ($section) => [(string) $section->id => $section->name])
            ->all(),
    ])->all();
@endphp

@section('content')
<form method="POST" action="{{ route('admin.students-results.students.add.store') }}"
      enctype="multipart/form-data"
      class="space-y-6"
      x-data="{
          level: @js((string) old('level_id', '')),
          section: @js((string) old('section_id', '')),
          pairs: @js($classPairs),
          file: null,
          over: false,
          get arms() { return this.pairs[this.level] ?? {}; },
      }">
    @csrf

    {{-- ================= Where they sit ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Academic details</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            {{-- No empty choice: a child is joining a session, and one of them is the
                 session the school is currently running. --}}
            <x-field name="academic_session_id" label="Academic year" type="select" required
                     :placeholder-option="false"
                     :value="$current?->id"
                     :options="$sessions->pluck('name', 'id')->all()" />

            {{-- Typed rather than issued. The number on the office's paper register is the
                 school's, and this is the child's own — it is also what they sign in with,
                 so it has to belong to nobody else. --}}
            <x-field name="student_number" label="Register No" required
                     hint="The admission number this child is known by, e.g. SAC/2026/014. They sign in with it." />

            <div>
                <label for="level_id" class="label">
                    Class <span class="text-rose-500">*</span>
                </label>

                <select id="level_id" name="level_id" required x-model="level" @change="section = ''"
                        class="input @error('level_id') input-error @enderror">
                    <option value="">Choose a class</option>

                    @foreach ($levels as $level)
                        <option value="{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
                </select>

                @error('level_id')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="section_id" class="label">
                    Section <span class="text-rose-500">*</span>
                </label>

                {{-- Shut until a class is chosen: the arms on offer are the arms that
                     class runs, so there is nothing to offer before it is known. --}}
                <select id="section_id" name="section_id" required
                        x-model="section" :disabled="level === ''"
                        class="input disabled:cursor-not-allowed disabled:bg-surface-2 @error('section_id') input-error @enderror">
                    <option value="" x-text="level === '' ? 'Select Class First' : 'Choose a section'"></option>

                    <template x-for="(name, id) in arms" :key="id">
                        <option :value="id" :selected="id === section" x-text="name"></option>
                    </template>
                </select>

                @error('section_id')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- ================= The child ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Student details</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <x-field name="first_name" label="First name" required />
            <x-field name="middle_name" label="Middle name" />
            <x-field name="last_name" label="Surname" required />

            <x-field name="gender" label="Gender" type="select"
                     placeholder-option="Not given"
                     :options="\App\Enums\Gender::options()" />

            <x-field name="date_of_birth" label="Date of birth" type="date" />

            {{-- No email and no phone for the child: a pupil of this school has neither,
                 and the school only ever writes to or texts the parent. --}}
            <x-field name="address" label="Address" class="sm:col-span-3" />
        </div>

        {{-- ================= Photograph ================= --}}
        <div class="border-t border-line p-5">
            <label class="label">Profile picture</label>

            {{-- A label wrapping the box, so a click anywhere in it opens the picker. A
                 dropped file is put straight onto the input, which is what makes dropping
                 and choosing the same thing to the form. --}}
            <label for="photo"
                   @dragover.prevent="over = true"
                   @dragleave.prevent="over = false"
                   @drop.prevent="over = false; $refs.photo.files = $event.dataTransfer.files; file = $event.dataTransfer.files[0]?.name ?? null"
                   :class="over
                       ? 'border-brand-400 bg-brand-50/60 dark:bg-brand-900/20'
                       : 'border-line bg-surface-2/40'"
                   class="mt-1 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center transition-colors">
                <x-nav-icon name="upload" class="h-8 w-8 text-muted" />

                <span class="text-sm font-medium text-ink-soft"
                      x-text="file ?? 'Drag and drop a file here or click'"></span>

                <span class="text-xs text-muted">
                    Optional — JPG, PNG or WEBP, up to 5 MB. It is the face printed beside
                    their name on the results sheet.
                </span>
            </label>

            <input x-ref="photo" id="photo" name="photo" type="file"
                   accept=".jpg,.jpeg,.png,.webp" class="sr-only"
                   @change="file = $event.target.files[0]?.name ?? null">

            @error('photo')
                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ================= The parent ================= --}}
    <div class="card">
        <div class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">Parent / guardian</h2>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <x-field name="guardian_name" label="Name" />
            <x-field name="guardian_phone" label="Phone"
                     hint="The number the school rings and texts." />
            <x-field name="guardian_email" label="Email" type="email" />
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary">Save</button>

        <a href="{{ route('admin.students-results.students') }}" class="btn-ghost">Cancel</a>

        <p class="text-xs text-muted">
            The child is given a portal login with the results and fees screens switched on.
            No bill is raised here — fees are invoiced from the Fees screen when they fall due.
        </p>
    </div>
</form>
@endsection
