@extends('layouts.admin')

@section('title', 'Edit applicant')
@section('subtitle', $applicant->full_name . ' · ' . $applicant->registration_number)

@section('actions')
    <a href="{{ route('admin.applicants.show', $applicant) }}" class="btn-ghost btn-sm">Back to applicant</a>
@endsection

@section('content')

@if ($errors->any())
    <x-alert tone="danger" title="Some details need fixing" class="mb-6">
        <ul class="list-inside list-disc space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<x-alert tone="info" class="mb-6">
    The registration number <span class="font-mono font-semibold">{{ $applicant->registration_number }}</span>
    never changes — it is the applicant's permanent reference. Status is normally moved by the examination
    pipeline, so only set it here to correct a mistake.
</x-alert>

<form method="POST" action="{{ route('admin.applicants.update', $applicant) }}">
    @csrf
    @method('PUT')

    {{-- ================= Applicant ================= --}}
    <div class="card-pad">
        <h2 class="font-display text-lg font-semibold text-ink">Applicant</h2>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="last_name" label="Surname" :value="$applicant->last_name" required />
            <x-field name="first_name" label="First name" :value="$applicant->first_name" required />
            <x-field name="middle_name" label="Middle name" :value="$applicant->middle_name" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field name="gender" label="Gender" type="select"
                     placeholder-option="Not stated"
                     :value="$applicant->gender?->value"
                     :options="['male' => 'Male', 'female' => 'Female']" />

            <x-field name="date_of_birth" label="Date of birth" type="date"
                     :value="$applicant->date_of_birth?->toDateString()" />

            <x-field name="level_applied_for_id" label="Class applying for" type="select"
                     placeholder-option="No class"
                     :value="$applicant->level_applied_for_id"
                     :options="$levels->pluck('name', 'id')->all()" />

            <x-field name="status" label="Status" type="select" required
                     :value="$applicant->status->value"
                     :options="$statuses" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="previous_school" label="Previous school" :value="$applicant->previous_school" />

            <x-field name="address" label="Home address" :value="$applicant->address" />
        </div>
    </div>

    {{-- ================= Contact ================= --}}
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Contact</h2>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field name="phone" label="Applicant's phone" :value="$applicant->phone" />
            <x-field name="email" label="Applicant's email" type="email" :value="$applicant->email" />
            <x-field name="state" label="State" :value="$applicant->state" />
            <x-field name="lga" label="LGA" :value="$applicant->lga" />
        </div>
    </div>

    {{-- ================= Guardian ================= --}}
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Parent / guardian</h2>
        <p class="mt-1 text-sm text-muted">The guardian's phone is where text messages are sent.</p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="guardian_name" label="Full name" :value="$applicant->guardian_name" />
            <x-field name="guardian_relationship" label="Relationship" :value="$applicant->guardian_relationship" />
            <x-field name="guardian_phone" label="Phone" :value="$applicant->guardian_phone" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="guardian_email" label="Email" type="email" :value="$applicant->guardian_email" />
        </div>
    </div>

    {{-- ================= Office use ================= --}}
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Office notes</h2>
        <p class="mt-1 text-sm text-muted">Only staff see this. Never shown to the applicant or the parent.</p>

        <div class="mt-5">
            <x-field name="admin_notes" label="Notes" type="textarea" :value="$applicant->admin_notes" />
        </div>
    </div>

    <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
        <p class="text-sm text-muted">
            Registered {{ $applicant->created_at->format('j F Y') }}
            @if ($applicant->submitted_at)
                · submitted {{ $applicant->submitted_at->format('j F Y, g:ia') }}
            @endif
        </p>

        <div class="ml-auto flex items-center gap-3">
            <a href="{{ route('admin.applicants.show', $applicant) }}" class="btn-ghost btn-sm">Cancel</a>
            <button type="submit" class="btn-primary btn-sm">Save changes</button>
        </div>
    </div>
</form>

@endsection
