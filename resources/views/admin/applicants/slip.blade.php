@extends('layouts.admin')

@section('title', 'Registration slip')
@section('subtitle', $applicant->full_name . ' · ' . $applicant->registration_number)

@section('actions')
    <button type="button" onclick="window.print()" class="btn-primary btn-sm">Print this slip</button>
    <a href="{{ route('admin.applicants.show', $applicant) }}" class="btn-ghost btn-sm">Back to applicant</a>
@endsection

@push('head')
    {{-- The admin chrome would otherwise print on top of the slip. --}}
    <style>
        @media print {
            aside, header, .print-hide { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
        }
    </style>
@endpush

@section('content')

<div class="mx-auto max-w-2xl">

    <p class="print-hide mb-6 rounded-xl bg-slate-100 p-4 text-sm text-slate-600">
        Print this and give it to the parent. It carries the registration number they must quote
        every time they contact the school.
    </p>

    <article class="card p-8">

        {{-- Letterhead --}}
        <header class="border-b border-slate-300 pb-5 text-center">
            <h1 class="font-display text-xl font-semibold tracking-tight text-slate-900">
                {{ $slip['school']['name'] }}
            </h1>

            @if ($slip['school']['address'])
                <p class="mt-1 text-sm text-slate-600">{{ $slip['school']['address'] }}</p>
            @endif

            @if ($slip['school']['phone'] || $slip['school']['email'])
                <p class="mt-1 text-sm text-slate-600">
                    {{ collect([$slip['school']['phone'], $slip['school']['email']])->filter()->implode(' · ') }}
                </p>
            @endif
        </header>

        <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow">Admission</p>
                <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Registration slip</h2>
            </div>

            <div class="text-right text-xs text-slate-500">
                <p>Issued {{ $slip['issuedOn']?->format('j F Y, g:ia') }}</p>
                @if ($slip['session'])
                    <p>Session {{ $slip['session'] }}</p>
                @endif
            </div>
        </div>

        {{-- The number is the whole point of the slip. --}}
        <div class="mt-6 rounded-2xl bg-brand-950 p-6 text-center">
            <p class="text-xs font-semibold uppercase tracking-widest text-gold-300">Registration number</p>
            <p class="mt-3 font-display text-3xl font-semibold tracking-wide text-white">
                {{ $applicant->registration_number }}
            </p>
            <p class="mt-3 text-sm text-brand-200">Quote this number in every contact with the school.</p>
        </div>

        {{-- Candidate --}}
        <dl class="mt-8 grid gap-x-8 gap-y-4 sm:grid-cols-2">
            @foreach (array_filter([
                ['Candidate', $applicant->full_name],
                ['Class applied for', $slip['level'] ?? '—'],
                ['Gender', $applicant->gender?->label()],
                ['Date of birth', $applicant->date_of_birth?->format('j F Y')],
                ['Parent / guardian', $applicant->guardian_name],
                ['Guardian phone', $applicant->guardian_phone ?? $applicant->phone],
            ]) as [$label, $value])
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Examination, when the candidate has been entered for one. --}}
        @if ($exam)
            <div class="mt-8 rounded-2xl border border-gold-300 bg-gold-50/60 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-gold-700">
                    Entrance examination
                </p>

                <p class="mt-2 font-display text-base font-semibold text-gold-950">
                    {{ $exam->displayTitle() }}
                </p>

                <dl class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gold-700">Date</dt>
                        <dd class="font-medium text-gold-950">
                            {{ $exam->exam_date?->format('l, j F Y') ?? 'To be announced' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gold-700">Time</dt>
                        <dd class="font-medium text-gold-950">
                            {{ $exam->starts_at ? \Illuminate\Support\Str::of($exam->starts_at)->substr(0, 5)->value() : 'To be announced' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gold-700">Venue</dt>
                        <dd class="font-medium text-gold-950">{{ $exam->venue ?? 'To be announced' }}</dd>
                    </div>
                </dl>

                @if ($exam->instructions)
                    <p class="mt-4 border-t border-gold-300/70 pt-3 text-sm text-gold-900">
                        {{ $exam->instructions }}
                    </p>
                @endif
            </div>
        @else
            <p class="mt-8 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-slate-200">
                {{ $applicant->full_name }} has not yet been entered for an entrance examination.
                The date and venue will be confirmed by the school office.
            </p>
        @endif

        {{-- What to bring, then the fallback: they can look it up themselves. --}}
        <p class="mt-6 text-sm text-slate-600">
            Please bring this slip, the candidate's birth certificate and two passport photographs when you
            report to the school office.
        </p>

        <div class="mt-8 border-t border-dashed border-slate-300 pt-4 text-xs text-slate-500">
            <p>
                Lost this slip? You can check the application at any time at
                <span class="font-mono">{{ route('public.status') }}</span>
                using the registration number above.
            </p>
        </div>
    </article>
</div>

@endsection
