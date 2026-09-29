@extends('layouts.admin')

@section('title', 'Register an applicant')
@section('subtitle', 'Enter a candidate from a paper application form')

@section('actions')
    <a href="{{ route('admin.applicants.import') }}" class="btn-secondary btn-sm">Bulk upload instead</a>
    <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Back to applicants</a>
@endsection

@section('content')

<x-alert tone="info" class="mb-6">
    <p class="font-semibold">
        Next registration number: <span class="font-mono">{{ $nextNumber }}</span>
        @if ($session) in {{ $session->name }} @endif
    </p>
    <p class="mt-1">
        It is allocated when you save, so the number below is only an estimate if somebody else registers
        at the same time. The parent's copy is the printable slip on the applicant's page.
    </p>
</x-alert>

<form method="POST" action="{{ route('admin.applicants.store') }}" enctype="multipart/form-data">
    @csrf

    {{-- A plain stacked form, not tabs: if anything is rejected the officer must
         be able to see which field and which message, wherever it is on the page. --}}
    @if ($errors->any())
        <x-alert tone="danger" title="Some details need fixing" class="mb-6">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    {{-- ================= 1. Applicant ================= --}}
    <div class="card-pad mt-6">
        <p class="eyebrow">Step 1</p>
        <h2 class="mt-1 font-display text-lg font-semibold text-ink">Applicant</h2>
        <p class="mt-1 text-sm text-muted">
            Only the name and the class are required here — the rest can be filled in later from the
            applicant's page. The parent's details, however, are needed to open the fee account.
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="last_name" label="Surname" :value="old('last_name')" required
                     placeholder="Okafor" />
            <x-field name="first_name" label="First name" :value="old('first_name')" required
                     placeholder="Chidera" />
            <x-field name="middle_name" label="Middle name" :value="old('middle_name')"
                     placeholder="Ada" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="gender" label="Gender" type="select"
                     placeholder-option="Not stated"
                     :value="old('gender')"
                     :options="['male' => 'Male', 'female' => 'Female']" />

            <x-field name="date_of_birth" label="Date of birth" type="date"
                     :value="old('date_of_birth')"
                     hint="Used to work out the age." />

            <x-field name="nationality" label="Nationality" :value="old('nationality', 'Nigerian')" />
        </div>

        <div class="mt-4 sm:max-w-md">
            <x-field name="level_applied_for_id" label="Class applying for" type="select"
                     placeholder-option="Choose a class"
                     :value="old('level_applied_for_id')"
                     :options="$levels->pluck('name', 'id')->all()"
                     required />
        </div>

        <div class="mt-4">
            <label for="photo" class="label">Passport photograph</label>
            <input id="photo" name="photo" type="file" accept="image/*"
                   class="input file:mr-3 file:rounded-md file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-sm">
            <p class="mt-1 text-xs text-muted">Optional. JPG, PNG or WEBP, up to 2 MB.</p>

            @error('photo')
                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ================= 2. Parent / guardian & contact ================= --}}
    <div class="card-pad mt-6">
        <p class="eyebrow">Step 2</p>
        <h2 class="mt-1 font-display text-lg font-semibold text-ink">Parent / guardian &amp; contact</h2>
        <p class="mt-1 text-sm text-muted">
            The parent's phone number and email are how the school reaches the family, and the details the
            fee account is opened in — so take them straight from the parent.
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <x-field name="guardian_phone" label="Parent phone number" :value="old('guardian_phone')"
                     placeholder="0803 000 0000" required
                     hint="Text messages are sent to this number." />

            <x-field name="guardian_email" label="Parent email address" type="email"
                     :value="old('guardian_email')" required
                     placeholder="parent@example.com"
                     hint="Used to open the fee account." />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="guardian_name" label="Parent / guardian name" :value="old('guardian_name')"
                     placeholder="Mrs. Ngozi Okafor" />

            <x-field name="guardian_relationship" label="Relationship" :value="old('guardian_relationship')"
                     placeholder="Mother, Father, Guardian…" />
        </div>

        <div class="mt-6 border-t border-line pt-5">
            <p class="text-xs font-semibold uppercase tracking-wider text-muted">Home address</p>

            <div class="mt-3">
                <x-field name="address" label="Address" :value="old('address')"
                         placeholder="House number, street, area" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-field name="city" label="Town / city" :value="old('city')" />
                <x-field name="state" label="State" type="select"
                         placeholder-option="Not stated"
                         :value="old('state')"
                         :options="$states" />
                <x-field name="lga" label="LGA" :value="old('lga')" />
            </div>
        </div>
    </div>

    {{-- ================= Save ================= --}}
    <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
        <p class="text-sm text-muted">
            Saving allocates the registration number and opens the applicant's record.
        </p>

        <div class="ml-auto flex items-center gap-3">
            <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Cancel</a>
            <button type="submit" class="btn-primary btn-sm">Register applicant</button>
        </div>
    </div>
</form>

@endsection
