@extends('layouts.public')

@section('title', 'Apply for admission')

@section('content')
<div class="bg-slate-50 py-12">
    <div class="section">

        {{-- ================= Heading ================= --}}
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow">Admission application</span>
            <h1 class="mt-5 font-display text-3xl font-semibold text-slate-900 text-balance sm:text-4xl">
                Apply for admission
            </h1>
            <p class="mt-4 text-slate-600">
                Complete the form below. As soon as you submit it you will receive a
                registration number — write it down and keep it safe, it is how you
                check your admission status.
            </p>
        </div>

        <div class="mx-auto mt-10 max-w-3xl">
            <x-stepper :steps="['Apply', 'Sit the exam', 'Results & fees']" :current="1" />
        </div>

        <div class="mx-auto mt-8 grid max-w-6xl gap-8 lg:grid-cols-3">

            {{-- ================= Form ================= --}}
            <form method="POST"
                  action="{{ route('public.register.store') }}"
                  enctype="multipart/form-data"
                  class="space-y-6 lg:col-span-2"
                  x-data="{ busy: false }"
                  @submit="busy = true">
                @csrf

                @if ($applicationFee > 0)
                    <x-alert tone="warning" title="Application fee">
                        A non-refundable application fee of
                        <strong>{{ $currency }}{{ number_format($applicationFee, 2) }}</strong>
                        is payable at the school office. You can submit this form first —
                        payment will be confirmed before your examination slip is issued.
                    </x-alert>
                @endif

                {{-- ---------- 1. Applicant ---------- --}}
                <div class="card-pad">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 font-display text-sm font-semibold text-gold-300">1</span>
                        <h2 class="text-base font-semibold text-slate-900">Applicant details</h2>
                    </div>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field name="last_name" label="Surname" required placeholder="Adewale" autocomplete="family-name" />
                        <x-field name="first_name" label="First name" required placeholder="Tunde" autocomplete="given-name" />
                        <x-field name="middle_name" label="Middle name" placeholder="Optional" />
                        <x-field name="gender" label="Gender" type="select" required
                                 :options="['male' => 'Male', 'female' => 'Female']" />
                        <x-field name="date_of_birth" label="Date of birth" type="date" required />
                        <x-field name="nationality" label="Nationality" placeholder="Nigerian" />
                    </div>
                </div>

                {{-- ---------- 2. Contact ---------- --}}
                <div class="card-pad">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 font-display text-sm font-semibold text-gold-300">2</span>
                        <h2 class="text-base font-semibold text-slate-900">Contact &amp; schooling</h2>
                    </div>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field name="phone" label="Phone number" required placeholder="0803 000 0000" inputmode="tel" />
                        <x-field name="email" label="Email address" type="email" placeholder="you@example.com"
                                 hint="Optional, but we use it to send you updates." />

                        <div class="sm:col-span-2">
                            <x-field name="address" label="Residential address" required placeholder="12 Adeola Street, Ikeja" />
                        </div>

                        <x-field name="city" label="Town / City" placeholder="Ikeja" />
                        <x-field name="state" label="State" type="select" required :options="$states" />
                        <x-field name="lga" label="Local government area" placeholder="Ikeja LGA" />
                        <x-field name="previous_school" label="Previous school" placeholder="Name of last school attended" />
                    </div>
                </div>

                {{-- ---------- 3. Class ---------- --}}
                <div class="card-pad">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 font-display text-sm font-semibold text-gold-300">3</span>
                        <h2 class="text-base font-semibold text-slate-900">Class you are applying for</h2>
                    </div>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field name="level_applied_for_id" label="Class" type="select" required
                                 placeholder-option="Choose a class"
                                 :options="$levels->pluck('name', 'id')->all()" />

                        <div class="flex items-end">
                            <p class="hint">
                                Your entrance examination will be set at this level. Verify your
                                choice carefully — it affects your cutoff mark.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- ---------- 4. Guardian ---------- --}}
                <div class="card-pad">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 font-display text-sm font-semibold text-gold-300">4</span>
                        <h2 class="text-base font-semibold text-slate-900">Parent / guardian</h2>
                    </div>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field name="guardian_name" label="Full name" required placeholder="Mr. &amp; Mrs. Adewale" />
                        <x-field name="guardian_relationship" label="Relationship" type="select" required
                                 placeholder-option="Choose"
                                 :options="['Father' => 'Father', 'Mother' => 'Mother', 'Guardian' => 'Guardian', 'Sibling' => 'Sibling', 'Sponsor' => 'Sponsor', 'Other' => 'Other']" />
                        <x-field name="guardian_phone" label="Phone number" required placeholder="0803 000 0000" inputmode="tel" />
                        <x-field name="guardian_email" label="Email address" type="email" placeholder="Optional" />

                        <div class="sm:col-span-2">
                            <x-field name="guardian_address" label="Contact address" placeholder="Optional — if different from above" />
                        </div>
                    </div>
                </div>

                {{-- ---------- 5. Uploads ---------- --}}
                <div class="card-pad">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 font-display text-sm font-semibold text-gold-300">5</span>
                        <h2 class="text-base font-semibold text-slate-900">Photograph &amp; documents</h2>
                    </div>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field name="photo" label="Passport photograph" type="file"
                                 accept="image/*"
                                 hint="JPG or PNG, up to 2 MB. A clear, recent photograph." />

                        <x-field name="documents[]" id="documents" label="Supporting documents" type="file"
                                 accept=".jpg,.jpeg,.png,.pdf"
                                 multiple
                                 hint="Up to 5 files: birth certificate, last result, etc. Max 4 MB each." />
                    </div>
                </div>

                {{-- ---------- Declaration ---------- --}}
                <div class="card-pad">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="declaration" value="1" required
                               @checked(old('declaration'))
                               class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                        <span class="text-sm text-slate-700">
                            I confirm that the information given on this form is true and correct,
                            and I understand that any false declaration may lead to the application
                            being cancelled.
                        </span>
                    </label>

                    @error('declaration')
                        <p class="error-text">{{ $message }}</p>
                    @enderror

                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <button type="submit" class="btn-primary btn-lg" x-bind:disabled="busy">
                            <span x-show="! busy">Submit application</span>
                            <span x-show="busy" x-cloak>Submitting…</span>
                        </button>

                        <p class="text-xs text-slate-500">
                            You will receive your registration number immediately.
                        </p>
                    </div>
                </div>
            </form>

            {{-- ================= Sidebar ================= --}}
            <aside class="space-y-6 lg:col-span-1">
                <div class="card-pad">
                    <h3 class="text-sm font-semibold text-slate-900">Before you start</h3>
                    <ul class="mt-4 space-y-3 text-sm text-slate-600">
                        @foreach ([
                            'The applicant\'s date of birth',
                            'A recent passport photograph',
                            'Birth certificate or last school result',
                            'Parent or guardian contact details',
                        ] as $item)
                            <li class="flex gap-2.5">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                </svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card-pad">
                    <h3 class="text-sm font-semibold text-slate-900">After you submit</h3>
                    <ol class="mt-4 space-y-4">
                        @foreach ([
                            'You get a registration number such as SAC-00001.',
                            'We schedule your entrance examination and tell you the date.',
                            'Your scripts are marked and the scores captured.',
                            'Pass the cutoff and you are transferred into the results and fees portals.',
                        ] as $index => $step)
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                                    {{ $index + 1 }}
                                </span>
                                <span class="text-sm text-slate-600">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="card-pad bg-brand-50/60">
                    <h3 class="text-sm font-semibold text-brand-900">Need help?</h3>
                    <p class="mt-2 text-sm text-brand-800">
                        Call the school office on
                        <a href="tel:{{ $school->phone }}" class="font-semibold underline decoration-brand-300 underline-offset-2">{{ $school->phone }}</a>
                        or visit us — we will help you complete the form.
                    </p>
                </div>
            </aside>
        </div>
    </div>
</div>
@endsection
