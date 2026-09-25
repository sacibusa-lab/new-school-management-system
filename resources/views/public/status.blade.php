@extends('layouts.public')

@section('title', 'Admission status')

@section('content')
<div class="bg-slate-50 py-12">
    <div class="section max-w-4xl">

        <div class="mx-auto max-w-2xl text-center">
            <span class="eyebrow">Admissions</span>
            <h1 class="mt-5 font-display text-3xl font-semibold text-slate-900 sm:text-4xl">
                Check your admission status
            </h1>
            <p class="mt-4 text-slate-600">
                Enter the registration number you were given when you applied.
                It looks like <span class="font-mono font-semibold text-slate-800">SAC-00001</span>.
            </p>
        </div>

        {{-- ================= Search ================= --}}
        <form method="GET" class="card-pad mx-auto mt-8 max-w-xl">
            <x-field name="registration_number" label="Registration number" required
                     placeholder="SAC-00001"
                     autofocus
                     autocomplete="off"
                     :value="request('registration_number')" />

            <button type="submit" class="btn-primary mt-5 w-full">Check status</button>

            <p class="mt-3 text-center text-xs text-slate-500">
                Lost your number? Call the school office on {{ $school->phone }}.
            </p>
        </form>

        {{-- ================= Result ================= --}}
        @if ($searched)
            <div class="mx-auto mt-8 max-w-2xl">
                @if ($applicant)
                    @php
                        $stage = $applicant->status->stage();
                        $steps = ['Registered', 'Exam scheduled', 'Exam completed', 'Cutoff applied', 'Decision'];
                    @endphp

                    <div class="card overflow-hidden">
                        {{-- Header --}}
                        <div class="border-b border-slate-200 bg-slate-50/70 p-5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-900 font-display text-base font-semibold text-gold-300">
                                        {{ $applicant->initials }}
                                    </span>
                                    <div>
                                        <p class="font-display text-lg font-semibold text-slate-900">{{ $applicant->full_name }}</p>
                                        <p class="font-mono text-sm text-slate-500">{{ $applicant->registration_number }}</p>
                                    </div>
                                </div>

                                <x-status-pill :status="$applicant->status" />
                            </div>
                        </div>

                        {{-- Timeline --}}
                        <div class="border-b border-slate-200 p-5 sm:p-6">
                            <x-stepper :steps="$steps" :current="max($stage, 1)" />
                        </div>

                        {{-- Verdict --}}
                        <div class="p-5 sm:p-6">
                            @if ($applicant->status === \App\Enums\ApplicantStatus::Admitted)
                                @php $decision = $applicant->decisions->sortByDesc('id')->first(); @endphp

                                <x-alert tone="success" title="Congratulations — you have been admitted!">
                                    You scored
                                    <strong>{{ $decision ? rtrim(rtrim(number_format((float) $decision->average_score, 2), '0'), '.') . '%' : 'above the' }}</strong>
                                    against a cutoff mark of
                                    <strong>{{ $decision ? rtrim(rtrim(number_format((float) $decision->cutoff_mark, 2), '0'), '.') : '—' }}%</strong>.
                                    You have been transferred into the school's results and fees portals.
                                </x-alert>

                                @if ($applicant->student)
                                    <div class="mt-6 rounded-2xl bg-brand-950 p-6 text-center">
                                        <p class="text-xs font-semibold uppercase tracking-widest text-gold-300">
                                            Your admission number
                                        </p>
                                        <p class="mt-3 font-display text-3xl font-semibold text-white">
                                            {{ $applicant->student->student_number }}
                                        </p>
                                        <p class="mt-3 text-sm text-brand-200">
                                            Use this from now on — it replaces your registration number
                                            ({{ $applicant->registration_number }}) for results and fees.
                                        </p>
                                    </div>

                                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                                        <a href="{{ route('public.results') }}" class="btn-primary">Check your results</a>
                                        <a href="{{ route('public.fees') }}" class="btn-secondary">View your fees</a>
                                    </div>
                                @endif

                            @elseif ($applicant->status === \App\Enums\ApplicantStatus::Rejected)
                                @php $decision = $applicant->decisions->sortByDesc('id')->first(); @endphp

                                <x-alert tone="danger" title="Not admitted on this occasion">
                                    @if ($decision)
                                        You scored
                                        <strong>{{ rtrim(rtrim(number_format((float) $decision->average_score, 2), '0'), '.') }}%</strong>
                                        against a cutoff mark of
                                        <strong>{{ rtrim(rtrim(number_format((float) $decision->cutoff_mark, 2), '0'), '.') }}%</strong>.
                                    @endif
                                    Please contact the school office if you would like feedback or to discuss your options.
                                </x-alert>

                                {{-- Self-service resit booking: no phone call needed. --}}
                                @if ($openResit)
                                    <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
                                        <p class="text-sm font-semibold text-emerald-900">
                                            You are registered for a resit
                                        </p>
                                        <p class="mt-1 text-sm text-emerald-800">
                                            Resit {{ $openResit->resit_round }} of {{ $openResit->title }}.
                                            @if ($openResit->exam_date)
                                                The examination holds on
                                                <strong>{{ $openResit->exam_date->format('l, j F Y') }}</strong>
                                                @if ($openResit->venue)
                                                    at <strong>{{ $openResit->venue }}</strong>
                                                @endif.
                                            @else
                                                The date will be published here shortly.
                                            @endif
                                        </p>
                                        <p class="mt-2 text-xs text-emerald-700">
                                            You only need to re-sit the papers you did not pass. Bring this
                                            registration number: <span class="font-mono font-semibold">{{ $applicant->registration_number }}</span>.
                                        </p>
                                    </div>
                                @elseif ($resitEnabled)
                                    <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Want another chance? Book a resit.
                                        </p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            You will re-sit only the papers you did not pass. Your other marks are kept.
                                        </p>

                                        <form method="POST" action="{{ route('public.resit.store') }}" class="mt-4">
                                            @csrf
                                            <input type="hidden" name="registration_number"
                                                   value="{{ $applicant->registration_number }}">

                                            <button type="submit" class="btn-primary btn-sm">
                                                Register for the resit examination
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <p class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                                        Resit applications are closed at the moment. Please contact the school
                                        office to discuss your options.
                                    </p>
                                @endif

                            @elseif (in_array($applicant->status, [\App\Enums\ApplicantStatus::ExamCompleted, \App\Enums\ApplicantStatus::Shortlisted], true))
                                <x-alert tone="warning" title="Your scripts have been marked">
                                    The examination officer is finalising the merit list and the cutoff mark.
                                    Your decision will appear here as soon as it is published.
                                </x-alert>

                            @else
                                <x-alert tone="info" title="Your application is in progress">
                                    You are registered for the entrance examination.
                                    @if ($applicant->academicSession)
                                        Session: <strong>{{ $applicant->academicSession->name }}</strong>.
                                    @endif
                                    Please check back after the examination for your result and decision.
                                </x-alert>
                            @endif

                            {{-- Details --}}
                            <dl class="mt-6 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                                @foreach ([
                                    ['Class applied for', $applicant->levelAppliedFor?->name ?? '—'],
                                    ['Session', $applicant->academicSession?->name ?? '—'],
                                    ['Applied on', $applicant->submitted_at?->format('j F Y') ?? '—'],
                                ] as [$label, $value])
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</dt>
                                        <dd class="mt-1 text-sm text-slate-800">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>

                @else
                    <x-alert tone="danger" title="No match found">
                        We could not find an application with the registration number
                        <span class="font-mono font-semibold">{{ request('registration_number') }}</span>.
                        Check the number carefully — it looks like
                        <span class="font-mono">SAC-00001</span>, with the letters and numbers in that order.
                    </x-alert>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
