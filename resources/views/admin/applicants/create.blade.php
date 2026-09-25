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
        <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Applicant</h2>
        <p class="mt-1 text-sm text-slate-500">
            Only the name and the class are required — everything else can be filled in later from the
            applicant's page.
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

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="level_applied_for_id" label="Class applying for" type="select"
                     placeholder-option="Choose a class"
                     :value="old('level_applied_for_id')"
                     :options="$levels->pluck('name', 'id')->all()"
                     required />

            <x-field name="previous_school" label="Previous school" :value="old('previous_school')"
                     placeholder="Where they are coming from" />
        </div>

        <div class="mt-4">
            <label for="photo" class="label">Passport photograph</label>
            <input id="photo" name="photo" type="file" accept="image/*"
                   class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm">
            <p class="mt-1 text-xs text-slate-500">Optional. JPG, PNG or WEBP, up to 2 MB.</p>

            @error('photo')
                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <label for="documents" class="label">Supporting documents</label>
            <input id="documents" name="documents[]" type="file" multiple
                   accept=".jpg,.jpeg,.png,.pdf"
                   class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm">
            <p class="mt-1 text-xs text-slate-500">
                Optional. Birth certificate, previous result or report sheet. Up to 5 files, 4 MB each,
                as JPG, PNG or PDF. They are listed on the applicant's page for you to open later.
            </p>

            @error('documents')
                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
            @enderror

            @error('documents.*')
                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ================= 2. Contact ================= --}}
    <div class="card-pad mt-6">
        <p class="eyebrow">Step 2</p>
        <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Contact & address</h2>
        <p class="mt-1 text-sm text-slate-500">Useful for the result and fee portals, and for reaching the family.</p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <x-field name="phone" label="Applicant's phone" :value="old('phone')" placeholder="0803 000 0000" />
            <x-field name="email" label="Applicant's email" type="email" :value="old('email')" />
        </div>

        <div class="mt-4">
            <x-field name="address" label="Home address" :value="old('address')"
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

    {{-- ================= 3. Guardian ================= --}}
    <div class="card-pad mt-6">
        <p class="eyebrow">Step 3</p>
        <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Parent / guardian</h2>
        <p class="mt-1 text-sm text-slate-500">
            The guardian's phone number is the one text messages are sent to, so it is worth getting right.
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-3">
            <x-field name="guardian_name" label="Full name" :value="old('guardian_name')"
                     placeholder="Mrs. Ngozi Okafor" />
            <x-field name="guardian_relationship" label="Relationship" :value="old('guardian_relationship')"
                     placeholder="Mother, Father, Guardian…" />
            <x-field name="guardian_phone" label="Phone" :value="old('guardian_phone')"
                     placeholder="0803 000 0000" hint="Text messages go here." />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="guardian_email" label="Email" type="email" :value="old('guardian_email')" />
            <x-field name="guardian_address" label="Address (if different)" :value="old('guardian_address')" />
        </div>
    </div>

    {{-- ================= Save ================= --}}
    <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
        <p class="text-sm text-slate-500">
            Saving allocates the registration number and opens the applicant's record.
        </p>

        <div class="ml-auto flex items-center gap-3">
            <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Cancel</a>
            <button type="submit" class="btn-primary btn-sm">Register applicant</button>
        </div>
    </div>
</form>

@endsection
