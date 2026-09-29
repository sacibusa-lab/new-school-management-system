@extends('layouts.public')

@section('title', 'Admission status')

@php
    // Two decimals at most, with trailing zeros dropped: 72, 72.5, 72.55.
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp

@section('content')
<div class="bg-slate-50 py-12 print:bg-white print:py-0">
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
        <form method="GET" class="card-pad mx-auto mt-8 max-w-xl print:hidden">
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
                        // $decision and $papers arrive from the controller.
                        $rejected = $applicant->status === \App\Enums\ApplicantStatus::Rejected;
                        $marked = in_array($applicant->status, [
                            \App\Enums\ApplicantStatus::ExamCompleted,
                            \App\Enums\ApplicantStatus::Shortlisted,
                        ], true);
                    @endphp

                    <div class="card overflow-hidden">

                        {{-- ============ Who this is ============ --}}
                        <div class="flex flex-wrap items-center gap-5 border-b border-slate-200 bg-white p-5 sm:p-6">
                            {{-- The photograph is what identifies the candidate at a glance,
                                 which is the whole reason for asking for one. --}}
                            @if ($applicant->photo_path)
                                <img src="{{ asset('storage/' . $applicant->photo_path) }}"
                                     alt="Passport photograph of {{ $applicant->full_name }}"
                                     class="h-20 w-16 shrink-0 rounded-xl object-cover ring-1 ring-slate-200">
                            @else
                                <span class="inline-flex h-20 w-16 shrink-0 items-center justify-center rounded-xl bg-brand-900 font-display text-lg font-semibold text-gold-300">
                                    {{ $applicant->initials }}
                                </span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="font-display text-xl font-semibold text-slate-900">
                                    {{ $applicant->full_name }}
                                </p>
                                <p class="mt-0.5 font-mono text-sm text-slate-500">
                                    {{ $applicant->registration_number }}
                                </p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $applicant->levelAppliedFor?->name ?? '—' }}
                                    @if ($applicant->academicSession)
                                        · {{ $applicant->academicSession->name }} session
                                    @endif
                                </p>
                            </div>

                            <x-status-pill :status="$applicant->status" />
                        </div>

                        {{-- ============ Entrance examination ============ --}}
                        {{-- The figures first, then the papers they were added up from,
                             so a parent can check the arithmetic for themselves. --}}
                        @if ($decision)
                            <div class="p-5 sm:p-6">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    Entrance examination
                                </p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-center ring-1 ring-slate-200">
                                        <p class="font-display text-2xl font-semibold text-slate-900">
                                            {{ $fmt($decision->total_score) }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            Total over {{ $decision->subjects_offered }} paper(s)
                                        </p>
                                    </div>

                                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-center ring-1 ring-slate-200">
                                        <p class="font-display text-2xl font-semibold text-slate-900">
                                            {{ $fmt($decision->average_score) }}%
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">Average</p>
                                    </div>

                                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-center ring-1 ring-slate-200">
                                        <p class="font-display text-2xl font-semibold text-slate-900">
                                            {{ $fmt($decision->cutoff_mark) }}%
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">Cutoff mark</p>
                                    </div>
                                </div>

                                @if ($decision->subjects_failed > 0)
                                    <p class="mt-3 text-center text-xs text-slate-500">
                                        {{ $decision->subjects_passed }} paper(s) passed,
                                        {{ $decision->subjects_failed }} not passed{{ $decision->has_absent ? ', including a paper sat as absent' : '' }}.
                                    </p>
                                @endif

                                @if ($papers->isNotEmpty())
                                    <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-slate-200">
                                        <table class="w-full text-sm">
                                            <thead class="bg-slate-50">
                                                <tr>
                                                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                        Paper
                                                    </th>
                                                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                        Mark
                                                    </th>
                                                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                        %
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 bg-white">
                                                @foreach ($papers as $paper)
                                                    <tr>
                                                        <td class="px-4 py-2.5 text-slate-800">{{ $paper['name'] }}</td>
                                                        <td class="px-4 py-2.5 text-right">
                                                            @if ($paper['is_absent'])
                                                                <span class="font-medium text-rose-600">Absent</span>
                                                            @else
                                                                <span class="font-mono text-slate-600">{{ $fmt($paper['score']) }} / {{ $fmt($paper['total_marks']) }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-2.5 text-right font-semibold">
                                                            {{-- A paper the school never recorded cannot be graded,
                                                                 so it says nothing rather than showing 0%. --}}
                                                            @if ($paper['is_absent'] || ! $paper['gradeable'])
                                                                <span class="text-slate-400">—</span>
                                                            @else
                                                                <span class="{{ $paper['passed'] ? 'text-emerald-700' : 'text-rose-600' }}">
                                                                    {{ $fmt($paper['percentage']) }}%
                                                                </span>
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

                        {{-- ============ The verdict ============ --}}
                        @if ($applicant->isAdmitted())
                            <div class="bg-emerald-50 p-6 text-center sm:p-8">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-600 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-emerald-900 sm:text-3xl">
                                    Congratulations — you have been admitted
                                </p>

                                @if ($decision)
                                    <p class="mx-auto mt-3 max-w-md text-sm text-emerald-800">
                                        You scored <strong>{{ $fmt($decision->average_score) }}%</strong>
                                        against a cutoff mark of
                                        <strong>{{ $fmt($decision->cutoff_mark) }}%</strong>.
                                        Your place is confirmed.
                                    </p>
                                @endif
                            </div>

                            @if ($applicant->student)
                                <div class="border-t border-slate-200 p-6 sm:p-8">
                                    <div class="rounded-2xl bg-brand-950 p-6 text-center">
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
                                </div>
                            @endif

                        @elseif ($rejected)
                            <div class="bg-rose-50 p-6 text-center sm:p-8">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-600 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-rose-900 sm:text-3xl">
                                    Not admitted on this occasion
                                </p>

                                @if ($decision)
                                    <p class="mx-auto mt-3 max-w-md text-sm text-rose-800">
                                        You scored <strong>{{ $fmt($decision->average_score) }}%</strong>
                                        against a cutoff mark of
                                        <strong>{{ $fmt($decision->cutoff_mark) }}%</strong>.
                                    </p>
                                @endif

                                <p class="mx-auto mt-3 max-w-md text-sm text-rose-800">
                                    Please contact the school office if you would like feedback or to
                                    discuss your options.
                                </p>
                            </div>

                        @elseif ($marked)
                            <div class="bg-gold-50 p-6 text-center sm:p-8">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-gold-500 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-gold-900">
                                    Your scripts have been marked
                                </p>
                                <p class="mx-auto mt-3 max-w-md text-sm text-gold-900">
                                    The examination officer is finalising the merit list and the cutoff
                                    mark. Your decision will appear here as soon as it is published.
                                </p>
                            </div>

                        @else
                            <div class="bg-brand-50 p-6 text-center sm:p-8">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-700 text-white">
                                    <x-nav-icon name="clipboard" class="h-7 w-7" />
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-brand-900">
                                    Your application is in progress
                                </p>
                                <p class="mx-auto mt-3 max-w-md text-sm text-brand-800">
                                    You are registered for the entrance examination. Please check back
                                    after the examination for your result and decision.
                                </p>
                            </div>
                        @endif

                        {{-- ============ Next steps ============ --}}
                        @if ($rejected)
                            <div class="border-t border-slate-200 p-5 sm:p-6">
                                @if ($openResit)
                                    {{-- Self-service resit booking: no phone call needed. --}}
                                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
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
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Want another chance? Book a resit.
                                        </p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            You will re-sit only the papers you did not pass. Your other marks are kept.
                                        </p>

                                        {{-- The whole point of this screen for a failed candidate, so it
                                             is the loudest thing on the page rather than a small
                                             button a parent can scroll past. --}}
                                        <form method="POST" action="{{ route('public.resit.store') }}" class="mt-4 print:hidden">
                                            @csrf
                                            <input type="hidden" name="registration_number"
                                                   value="{{ $applicant->registration_number }}">

                                            <button type="submit" class="btn-danger btn-lg w-full">
                                                Register for the resit examination
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <p class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                                        Resit applications are closed at the moment. Please contact the school
                                        office to discuss your options.
                                    </p>
                                @endif
                            </div>
                        @endif

                        {{-- ============ Details ============ --}}
                        <div class="border-t border-slate-200 p-5 sm:p-6">
                            <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-3">
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

                            {{-- Parents screenshot and print this, so offer a clean one. --}}
                            <div class="mt-5 print:hidden">
                                <button type="button" onclick="window.print()" class="btn-secondary btn-sm">
                                    Print or save this page
                                </button>
                            </div>
                        </div>
                    </div>

                    <p class="mt-4 text-center text-xs text-slate-500">
                        This is the record held for
                        <span class="font-mono">{{ $applicant->registration_number }}</span>.
                        Tell the school office straight away if anything here is wrong.
                    </p>

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
