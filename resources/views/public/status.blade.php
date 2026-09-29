@extends('layouts.public')

@section('title', 'Admission status')

@php
    // Two decimals at most, with trailing zeros dropped: 72, 72.5, 72.55.
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp

@section('content')
<div class="bg-surface-2 py-12 print:bg-surface print:py-0">
    <div class="section max-w-4xl">

        {{-- The invitation to search means nothing on a printed record. --}}
        <div class="mx-auto max-w-2xl text-center print:hidden">
            <span class="eyebrow">Admissions</span>
            <h1 class="mt-5 font-display text-3xl font-semibold text-ink sm:text-4xl">
                Check your admission status
            </h1>
            <p class="mt-4 text-ink-soft">
                Enter the registration number you were given when you applied, and the
                candidate's surname. The number looks like
                <span class="font-mono font-semibold text-ink-soft">SAC-00001</span>.
            </p>
        </div>

        {{-- ================= Search ================= --}}
        <form method="GET" class="card-pad mx-auto mt-8 max-w-xl print:hidden">
            <x-field name="registration_number" label="Registration number" required
                     placeholder="SAC-00001"
                     autofocus
                     autocomplete="off"
                     :value="request('registration_number')" />

            {{-- Asked for because the number alone is not a secret: SAC-00001,
                 SAC-00002 … can simply be counted through. --}}
            <x-field name="surname" label="Candidate's surname" required
                     class="mt-4"
                     placeholder="Okafor"
                     autocomplete="off"
                     hint="A surname is all we ask for — it does not have to be spelled exactly right."
                     :value="request('surname')" />

            <button type="submit" class="btn-primary mt-5 w-full">Check status</button>

            <p class="mt-3 text-center text-xs text-muted">
                Lost your number? Call the school office on {{ $school->phone }}.
            </p>
        </form>

        {{-- ================= Result ================= --}}
        @if ($searched)
            <div class="mx-auto mt-8 max-w-2xl print:mt-0">
                {{-- ============ Letterhead, on paper only ============ --}}
                {{-- The site header carries the school's name and logo, but it is
                     deliberately hidden when printing — which left the printed record
                     anonymous: a sheet a parent hands to a relative or an employer with
                     nothing on it saying which school it came from. This is that
                     identity, restored for paper. --}}
                <div class="hidden print:mb-5 print:flex print:items-start print:justify-between print:gap-6 print:border-b print:border-line print:pb-4">
                    <div class="flex items-center gap-3">
                        <x-brand-mark size="lg" />

                        <div>
                            <p class="font-display text-lg font-semibold text-ink">{{ $school->name }}</p>

                            @if ($school->address)
                                <p class="text-xs text-ink-soft">{{ $school->address }}</p>
                            @endif

                            @php $contact = collect([$school->phone, $school->email])->filter()->implode(' · '); @endphp
                            @if ($contact)
                                <p class="text-xs text-ink-soft">{{ $contact }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted">
                            Admission status record
                        </p>
                        <p class="mt-1 text-xs text-ink-soft">Printed {{ now()->format('j F Y') }}</p>
                        @if ($applicant)
                            <p class="mt-0.5 font-mono text-xs text-ink-soft">
                                {{ $applicant->registration_number }}
                            </p>
                        @endif
                    </div>
                </div>

                @if ($applicant)
                    @php
                        // $decision and $papers arrive from the controller.
                        $rejected = $applicant->status === \App\Enums\ApplicantStatus::Rejected;
                        // Shortlisted means the cutoff was applied and they are waiting
                        // for a place — NOT that their scripts are still being marked.
                        // The two used to share a message, and it contradicted the
                        // marks sitting right above it on the page.
                        $waiting = $applicant->status === \App\Enums\ApplicantStatus::Shortlisted;
                        $marked = $applicant->status === \App\Enums\ApplicantStatus::ExamCompleted;
                    @endphp

                    <div class="card overflow-hidden print:break-inside-avoid">

                        {{-- ============ Who this is ============ --}}
                        <div class="flex flex-wrap items-center gap-5 border-b border-line bg-surface p-5 sm:p-6 print:gap-3 print:p-3">
                            {{-- The photograph is what identifies the candidate at a glance,
                                 which is the whole reason for asking for one. --}}
                            @if ($applicant->photo_path)
                                <img src="{{ asset('storage/' . $applicant->photo_path) }}"
                                     alt="Passport photograph of {{ $applicant->full_name }}"
                                     class="h-20 w-16 shrink-0 rounded-xl object-cover ring-1 ring-line">
                            @else
                                <span class="inline-flex h-20 w-16 shrink-0 items-center justify-center rounded-xl bg-brand-900 font-display text-lg font-semibold text-gold-300">
                                    {{ $applicant->initials }}
                                </span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="font-display text-xl font-semibold text-ink">
                                    {{ $applicant->full_name }}
                                </p>
                                <p class="mt-0.5 font-mono text-sm text-muted">
                                    {{ $applicant->registration_number }}
                                </p>
                                <p class="mt-1 text-sm text-ink-soft">
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
                            <div class="p-5 sm:p-6 print:p-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Entrance examination
                                </p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div class="rounded-xl bg-surface-2 px-4 py-3 text-center ring-1 ring-line">
                                        <p class="font-display text-2xl font-semibold text-ink">
                                            {{ $fmt($decision->total_score) }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-muted">
                                            Total over {{ $decision->subjects_offered }} paper(s)
                                        </p>
                                    </div>

                                    <div class="rounded-xl bg-surface-2 px-4 py-3 text-center ring-1 ring-line">
                                        <p class="font-display text-2xl font-semibold text-ink">
                                            {{ $fmt($decision->average_score) }}%
                                        </p>
                                        <p class="mt-0.5 text-xs text-muted">Average</p>
                                    </div>

                                    <div class="rounded-xl bg-surface-2 px-4 py-3 text-center ring-1 ring-line">
                                        <p class="font-display text-2xl font-semibold text-ink">
                                            {{ $fmt($decision->cutoff_mark) }}%
                                        </p>
                                        <p class="mt-0.5 text-xs text-muted">Cutoff mark</p>
                                    </div>
                                </div>

                                @if ($decision->subjects_failed > 0)
                                    <p class="mt-3 text-center text-xs text-muted">
                                        {{ $decision->subjects_passed }} paper(s) passed,
                                        {{ $decision->subjects_failed }} not passed{{ $decision->has_absent ? ', including a paper sat as absent' : '' }}.
                                    </p>
                                @endif

                                @if ($papers->isNotEmpty())
                                    <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-line">
                                        <table class="w-full text-sm">
                                            <thead class="bg-surface-2">
                                                <tr>
                                                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-muted">
                                                        Paper
                                                    </th>
                                                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-muted">
                                                        Mark
                                                    </th>
                                                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-muted">
                                                        %
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-line-soft bg-surface">
                                                @foreach ($papers as $paper)
                                                    <tr>
                                                        <td class="px-4 py-2.5 text-ink-soft">{{ $paper['name'] }}</td>
                                                        <td class="px-4 py-2.5 text-right">
                                                            @if ($paper['is_absent'])
                                                                <span class="font-medium text-rose-600 dark:text-rose-400">Absent</span>
                                                            @else
                                                                <span class="font-mono text-ink-soft">{{ $fmt($paper['score']) }} / {{ $fmt($paper['total_marks']) }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-2.5 text-right font-semibold">
                                                            {{-- A paper the school never recorded cannot be graded,
                                                                 so it says nothing rather than showing 0%. --}}
                                                            @if ($paper['is_absent'] || ! $paper['gradeable'])
                                                                <span class="text-muted">—</span>
                                                            @else
                                                                <span class="{{ $paper['passed'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-400' }}">
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
                            <div class="bg-emerald-50 dark:bg-emerald-950/40 p-6 text-center sm:p-8 print:py-4">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-600 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-emerald-900 dark:text-emerald-100 sm:text-3xl">
                                    Congratulations — you have been admitted
                                </p>

                                @if ($decision)
                                    <p class="mx-auto mt-3 max-w-md text-sm text-emerald-800 dark:text-emerald-200">
                                        You scored <strong>{{ $fmt($decision->average_score) }}%</strong>
                                        against a cutoff mark of
                                        <strong>{{ $fmt($decision->cutoff_mark) }}%</strong>.
                                        Your place is confirmed.
                                    </p>
                                @endif
                            </div>

                            @if ($applicant->student)
                                <div class="border-t border-line p-6 sm:p-8 print:p-3">
                                    <div class="rounded-2xl bg-brand-950 p-6 text-center print:p-4">
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
                            <div class="bg-rose-50 dark:bg-rose-950/40 p-6 text-center sm:p-8 print:py-4">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-600 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-rose-900 dark:text-rose-100 sm:text-3xl">
                                    Not admitted on this occasion
                                </p>

                                @if ($decision)
                                    <p class="mx-auto mt-3 max-w-md text-sm text-rose-800 dark:text-rose-200">
                                        You scored <strong>{{ $fmt($decision->average_score) }}%</strong>
                                        against a cutoff mark of
                                        <strong>{{ $fmt($decision->cutoff_mark) }}%</strong>.
                                    </p>
                                @endif

                                <p class="mx-auto mt-3 max-w-md text-sm text-rose-800 dark:text-rose-200">
                                    Please contact the school office if you would like feedback or to
                                    discuss your options.
                                </p>
                            </div>

                        @elseif ($waiting)
                            <div class="bg-sky-50 dark:bg-sky-950/40 p-6 text-center sm:p-8 print:py-4">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-600 text-white">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    </svg>
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-sky-900 dark:text-sky-100">
                                    You are on the waiting list
                                </p>
                                <p class="mx-auto mt-3 max-w-md text-sm text-sky-900 dark:text-sky-100">
                                    You passed the cutoff mark, but the places in
                                    {{ $applicant->levelAppliedFor?->name ?? 'your class' }} were filled before
                                    your name came up. Please watch this page — the school will contact you if a
                                    place becomes free, so keep your phone reachable.
                                </p>
                            </div>

                        @elseif ($marked)
                            <div class="bg-gold-50 dark:bg-gold-950/40 p-6 text-center sm:p-8 print:py-4">
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
                            <div class="bg-brand-50 dark:bg-brand-900/30 p-6 text-center sm:p-8 print:py-4">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-700 text-white">
                                    <x-nav-icon name="clipboard" class="h-7 w-7" />
                                </span>

                                <p class="mt-4 font-display text-2xl font-semibold text-brand-900 dark:text-brand-100">
                                    Your application is in progress
                                </p>
                                <p class="mx-auto mt-3 max-w-md text-sm text-brand-800 dark:text-brand-200">
                                    You are registered for the entrance examination. Please check back
                                    after the examination for your result and decision.
                                </p>
                            </div>
                        @endif

                        {{-- ============ Next steps ============ --}}
                        @if ($rejected)
                            <div class="border-t border-line p-5 sm:p-6 print:p-3">
                                @if ($openResit)
                                    {{-- Self-service resit booking: no phone call needed. --}}
                                    <div class="rounded-2xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/60 p-5">
                                        <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">
                                            You are registered for a resit
                                        </p>
                                        <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
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
                                        <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">
                                            You only need to re-sit the papers you did not pass. Bring this
                                            registration number: <span class="font-mono font-semibold">{{ $applicant->registration_number }}</span>.
                                        </p>
                                    </div>
                                @elseif ($resitEnabled)
                                    <div class="rounded-2xl border border-line bg-surface-2 p-5">
                                        <p class="text-sm font-semibold text-ink">
                                            Want another chance? Book a resit.
                                        </p>
                                        <p class="mt-1 text-sm text-ink-soft">
                                            You will re-sit only the papers you did not pass. Your other marks are kept.
                                        </p>

                                        {{-- The whole point of this screen for a failed candidate, so it
                                             is the loudest thing on the page rather than a small
                                             button a parent can scroll past. --}}
                                        <form method="POST" action="{{ route('public.resit.store') }}" class="mt-4 print:hidden">
                                            @csrf
                                            <input type="hidden" name="registration_number"
                                                   value="{{ $applicant->registration_number }}">
                                            {{-- Booked from a page the parent already opened
                                                 with the surname, so it is carried rather than
                                                 asked for twice. The booking checks it. --}}
                                            <input type="hidden" name="surname"
                                                   value="{{ $applicant->last_name }}">

                                            <button type="submit" class="btn-danger btn-lg w-full">
                                                Register for the resit examination
                                            </button>
                                        </form>

                                        {{-- The button is a screen action and stays off the paper.
                                             On its own that would leave the printed record ending on
                                             a question with no answer, so paper gets the answer
                                             instead. --}}
                                        <p class="mt-3 hidden text-sm text-ink-soft print:block">
                                            To book the resit, open the Admission Status page on the
                                            school's website, or call the office
                                            @if ($school->phone)
                                                on {{ $school->phone }}
                                            @endif
                                            with this registration number.
                                        </p>
                                    </div>
                                @else
                                    <p class="rounded-2xl border border-line bg-surface-2 p-5 text-sm text-ink-soft">
                                        Resit applications are closed at the moment. Please contact the school
                                        office to discuss your options.
                                    </p>
                                @endif
                            </div>
                        @endif

                        {{-- ============ Details ============ --}}
                        <div class="border-t border-line p-5 sm:p-6 print:p-3">
                            <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-3">
                                @foreach ([
                                    ['Class applied for', $applicant->levelAppliedFor?->name ?? '—'],
                                    ['Session', $applicant->academicSession?->name ?? '—'],
                                    ['Applied on', $applicant->submitted_at?->format('j F Y') ?? '—'],
                                ] as [$label, $value])
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $label }}</dt>
                                        <dd class="mt-1 text-sm text-ink-soft">{{ $value }}</dd>
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

                    <p class="mt-4 text-center text-xs text-muted">
                        This is the record held for
                        <span class="font-mono">{{ $applicant->registration_number }}</span>.
                        Tell the school office straight away if anything here is wrong.
                    </p>

                @else
                    <x-alert tone="danger" title="No match found">
                        We could not find an application with that registration number and
                        surname. Check both: the number looks like
                        <span class="font-mono">SAC-00001</span>, with the letters and numbers
                        in that order, and the surname is the candidate's own, as it was
                        written on the application.
                    </x-alert>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
