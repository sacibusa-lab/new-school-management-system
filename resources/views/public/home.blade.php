@extends('layouts.public')

@section('content')

{{-- ==================================================================
     Hero
     ================================================================== --}}
<section class="relative overflow-hidden bg-brand-950">
    <div class="absolute inset-0 hero-mesh"></div>
    <div class="absolute inset-0 grid-lines opacity-30"></div>

    <div class="section relative grid gap-14 py-20 lg:grid-cols-12 lg:py-28">
        <div class="lg:col-span-7">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-widest text-gold-300 ring-1 ring-white/15">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gold-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-gold-400"></span>
                </span>
                {{ $registrationOpen ? 'Admissions open · ' . $session?->name : 'Admissions · enquiries at the school office' }}
            </span>

            <h1 class="mt-7 font-display text-4xl leading-[1.1] font-semibold text-white text-balance sm:text-5xl lg:text-6xl">
                One portal for admissions,<br class="hidden sm:block">
                results and school fees.
            </h1>

            <p class="mt-7 max-w-2xl text-lg leading-relaxed text-brand-200">
                @if ($registrationOpen)
                    Apply for admission, sit the entrance examination, and — if you make the
                    cutoff — walk straight into a student account where your results and fee
                    records are already waiting. No queues. No separate logins.
                @else
                    Applications are taken at the school office, where your child is given a
                    permanent registration number such as <span class="font-mono text-white">SAC-00001</span>.
                    From there you can follow the entrance examination, the result and the fee
                    record here — no queues, and no separate logins.
                @endif
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-4">
                @if ($registrationOpen)
                    <a href="{{ route('public.register') }}" class="btn-gold btn-lg">
                        Start your application
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </a>
                    <a href="{{ route('public.status') }}" class="btn-lg inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 px-6 py-3 text-base font-semibold text-white transition hover:bg-white/10">
                        Check admission status
                    </a>
                @else
                    <a href="{{ route('public.status') }}" class="btn-gold btn-lg">
                        Check admission status
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </a>
                    <a href="{{ route('public.results') }}" class="btn-lg inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 px-6 py-3 text-base font-semibold text-white transition hover:bg-white/10">
                        Check a result
                    </a>
                @endif
            </div>

            <dl class="mt-14 grid max-w-2xl grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-4">
                @foreach ([
                    ['value' => $levels->count(), 'label' => 'Classes'],
                    ['value' => $subjectCount, 'label' => 'Subjects'],
                    ['value' => '3', 'label' => 'Portals'],
                    ['value' => '1', 'label' => 'Login'],
                ] as $stat)
                    <div>
                        <dd class="font-display text-3xl font-semibold text-white">{{ $stat['value'] }}</dd>
                        <dt class="mt-1 text-xs font-medium uppercase tracking-wider text-brand-300">{{ $stat['label'] }}</dt>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Live entry-examination card --}}
        <div class="lg:col-span-5">
            <div class="rounded-3xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur-xl">
                <p class="text-xs font-semibold uppercase tracking-widest text-gold-300">
                    Entrance examination
                </p>

                @if ($exam)
                    <p class="mt-3 font-display text-xl font-semibold text-white">{{ $exam->title }}</p>

                    <div class="mt-6 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-3">
                            <span class="text-brand-300">Date</span>
                            <span class="font-medium text-white">
                                {{ $exam->exam_date?->format('D, j M Y') ?? 'To be announced' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-3">
                            <span class="text-brand-300">Venue</span>
                            <span class="font-medium text-white">{{ $exam->venue ?? 'School campus' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-3">
                            <span class="text-brand-300">Subjects</span>
                            <span class="font-medium text-white">{{ $exam->examSubjects()->count() }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-brand-300">Applying for</span>
                            <span class="font-medium text-white">{{ $exam->level?->name ?? 'All levels' }}</span>
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-sm leading-relaxed text-brand-200">
                        The next entrance examination has not been scheduled yet. Registration for the
                        examination is handled at the school office.
                    </p>
                @endif

                <a href="{{ $registrationOpen ? route('public.register') : route('public.status') }}"
                   class="mt-7 flex items-center justify-center gap-2 rounded-xl bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:bg-white/15">
                    {{ $registrationOpen ? 'Register for the examination' : 'Check your admission status' }}
                </a>
            </div>
        </div>
    </div>
</section>

{{-- ==================================================================
     Three portals
     ================================================================== --}}
<section class="section -mt-10 relative z-10">
    <div class="grid gap-6 md:grid-cols-3">
        @php
            // The first card is the only one that moves with the switch: everything
            // else on the public site is self-service and always available.
            $admissionsCard = $registrationOpen
                ? [
                    'body' => 'Register online, get a permanent number like SAC-00001, and track your application through the entrance examination.',
                    'href' => route('public.register'),
                    'cta' => 'Apply now',
                ]
                : [
                    'body' => 'Register at the school office and you are given a permanent number like SAC-00001. Track the application through the entrance examination from here.',
                    'href' => route('public.status'),
                    'cta' => 'Check admission status',
                ];
        @endphp

        @foreach ([
            array_merge([
                'step' => '01',
                'title' => 'Admissions',
                'icon' => 'clipboard',
            ], $admissionsCard),
            [
                'step' => '02',
                'title' => 'Results',
                'body' => 'Scores are captured by your teachers and published term by term. Check your report card the moment it is released.',
                'icon' => 'chart',
                'href' => route('public.results'),
                'cta' => 'Check a result',
            ],
            [
                'step' => '03',
                'title' => 'School fees',
                'body' => 'See exactly what is owed, what has been paid and print your receipts — right up to date, with no phone calls.',
                'icon' => 'cash',
                'href' => route('public.fees'),
                'cta' => 'View fee status',
            ],
        ] as $card)
            <a href="{{ $card['href'] }}"
               class="group card relative overflow-hidden p-6 transition duration-200 hover:-translate-y-1 hover:shadow-lift">
                <div class="flex items-start justify-between">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-200 ring-1 ring-inset ring-brand-600/10">
                        <x-nav-icon :name="$card['icon']" class="h-6 w-6" />
                    </span>
                    <span class="font-display text-sm font-semibold text-slate-300">{{ $card['step'] }}</span>
                </div>

                <h3 class="mt-5 font-display text-lg font-semibold text-ink">{{ $card['title'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $card['body'] }}</p>

                <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 dark:text-brand-200">
                    {{ $card['cta'] }}
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </span>
            </a>
        @endforeach
    </div>
</section>

{{-- ==================================================================
     How it works
     ================================================================== --}}
<section class="section py-24">
    <div class="max-w-2xl">
        <span class="eyebrow">How it works</span>
        <h2 class="mt-5 font-display text-3xl font-semibold text-ink text-balance sm:text-4xl">
            From application to student account in four steps
        </h2>
        <p class="mt-4 text-ink-soft">
            The moment you pass the cutoff mark, the system does the paperwork for you —
            creating your student record, your portal login and your first fee invoice.
        </p>
    </div>

    <ol class="mt-14 grid gap-8 md:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['title' => 'Register', 'body' => $registrationOpen
                ? 'Fill the application form. You immediately receive a registration number such as SAC-00001.'
                : 'Register at the school office. You are given a registration number such as SAC-00001 on the spot.'],
            ['title' => 'Sit the exam', 'body' => 'Write the paper and pencil entrance examination on the scheduled date.'],
            ['title' => 'Marks are captured', 'body' => 'Teachers record your scores — typed in, uploaded from a spreadsheet, or read straight off the marked script.'],
            ['title' => 'You are transferred', 'body' => 'Pass the cutoff and you are moved automatically into the results and fees portals as SAC/' . now()->year . '/001.'],
        ] as $index => $step)
            <li class="relative">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-900 font-display text-sm font-semibold text-gold-300">
                    {{ $index + 1 }}
                </span>
                <h3 class="mt-5 text-base font-semibold text-ink">{{ $step['title'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $step['body'] }}</p>
            </li>
        @endforeach
    </ol>
</section>

{{-- ==================================================================
     Cutoff marks
     ================================================================== --}}
@if ($cutoffs->isNotEmpty())
    <section class="border-y border-line bg-surface py-20">
        <div class="section">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-xl">
                    <span class="eyebrow">Merit</span>
                    <h2 class="mt-5 font-display text-2xl font-semibold text-ink sm:text-3xl">
                        Cutoff marks for {{ $session?->name }}
                    </h2>
                    <p class="mt-3 text-sm text-ink-soft">
                        Set by the examination officer. Applicants who score at or above the
                        cutoff for their class are admitted automatically.
                    </p>
                </div>
                <a href="{{ route('public.status') }}" class="btn-secondary">Check your position</a>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cutoffs as $cutoff)
                    <div class="flex items-center justify-between gap-4 rounded-2xl border border-line bg-surface-2 p-5">
                        <div>
                            <p class="font-display text-lg font-semibold text-ink">{{ $cutoff->level?->name }}</p>
                            <p class="mt-0.5 text-xs text-muted">
                                {{ $cutoff->available_slots ? $cutoff->available_slots . ' places' : 'Unlimited places' }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-display text-2xl font-semibold text-brand-800 dark:text-brand-200">{{ rtrim(rtrim(number_format((float) $cutoff->cutoff_mark, 2), '0'), '.') }}%</p>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-muted">Cutoff</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- ==================================================================
     Closing call to action
     ================================================================== --}}
<section class="section py-24">
    <div class="relative overflow-hidden rounded-3xl bg-brand-900 px-8 py-14 sm:px-14">
        <div class="absolute inset-0 hero-mesh opacity-70"></div>

        <div class="relative flex flex-wrap items-center justify-between gap-8">
            <div class="max-w-xl">
                <h2 class="font-display text-2xl font-semibold text-white text-balance sm:text-3xl">
                    Ready to join {{ $school->name }}?
                </h2>

                @if ($registrationOpen)
                    <p class="mt-3 text-brand-200">
                        Registration takes about five minutes. Have the applicant's birth
                        certificate and a passport photograph ready.
                    </p>
                @else
                    <p class="mt-3 text-brand-200">
                        Come to the school office with the applicant's birth certificate and two
                        passport photographs. You will be given a registration number before you
                        leave, and can follow everything else from here.
                    </p>
                @endif
            </div>

            <a href="{{ $registrationOpen ? route('public.register') : route('public.status') }}"
               class="btn-gold btn-lg shrink-0">
                {{ $registrationOpen ? 'Apply for admission' : 'Check admission status' }}
            </a>
        </div>
    </div>
</section>

@endsection
