@extends('layouts.public')

@section('title', 'Registration slip')

@section('content')
<div class="bg-slate-50 py-12">
    <div class="section max-w-4xl">

        @if ($justRegistered)
            <div class="mx-auto max-w-2xl text-center">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/15">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                </span>
                <h1 class="mt-5 font-display text-3xl font-semibold text-slate-900">
                    Application received
                </h1>
                <p class="mt-3 text-slate-600">
                    Thank you, {{ $applicant->first_name }}. Your application has been registered.
                    Please save or print this slip — you will need your registration number to
                    check your admission status.
                </p>
            </div>
        @else
            <div class="mx-auto max-w-2xl text-center">
                <h1 class="font-display text-3xl font-semibold text-slate-900">Registration slip</h1>
                <p class="mt-3 text-slate-600">
                    Keep this slip safe. Present it at the examination venue.
                </p>
            </div>
        @endif

        {{-- ================= Registration number ================= --}}
        <div class="mx-auto mt-10 max-w-2xl" x-data="copyToClipboard('{{ $applicant->registration_number }}')">
            <div class="rounded-3xl bg-brand-950 px-8 py-9 text-center">
                <p class="text-xs font-semibold uppercase tracking-widest text-gold-300">
                    Your registration number
                </p>

                <p class="mt-4 font-display text-4xl font-semibold tracking-wide text-white sm:text-5xl">
                    {{ $applicant->registration_number }}
                </p>

                <button type="button"
                        @click="copy()"
                        class="mt-6 inline-flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/15">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75"/>
                    </svg>
                    <span x-show="! copied">Copy number</span>
                    <span x-show="copied" x-cloak>Copied!</span>
                </button>
            </div>
        </div>

        {{-- ================= Slip details ================= --}}
        <div class="card mt-8 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-6 py-5">
                <div class="flex items-center gap-3">
                    <x-brand-mark size="lg" />
                    <div>
                        <p class="font-display text-base font-semibold text-slate-900">{{ $slip['school']['name'] }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $slip['session'] ? 'Admission ' . $slip['session'] : 'Admission application' }}
                        </p>
                    </div>
                </div>

                <div class="text-right">
                    <p class="text-xs uppercase tracking-wider text-slate-500">Issued</p>
                    <p class="text-sm font-semibold text-slate-900">
                        {{ $slip['issuedOn']?->format('j M Y, g:ia') }}
                    </p>
                </div>
            </div>

            <dl class="grid gap-x-8 gap-y-6 px-6 py-7 sm:grid-cols-2">
                @foreach ([
                    ['label' => 'Applicant name', 'value' => $applicant->full_name],
                    ['label' => 'Registration number', 'value' => $applicant->registration_number, 'mono' => true],
                    ['label' => 'Class applied for', 'value' => $slip['level'] ?? '—'],
                    ['label' => 'Date of birth', 'value' => $applicant->date_of_birth?->format('j F Y') ?? '—'],
                    ['label' => 'Gender', 'value' => $applicant->gender?->label() ?? '—'],
                    ['label' => 'Phone', 'value' => $applicant->phone ?? '—'],
                    ['label' => 'Parent / guardian', 'value' => $applicant->guardian_name ?? '—'],
                    ['label' => 'Guardian phone', 'value' => $applicant->guardian_phone ?? '—'],
                    ['label' => 'Status', 'value' => $applicant->status->label(), 'badge' => $applicant->status->badge()],
                ] as $row)
                    <div @class(['sm:col-span-2' => ($row['label'] ?? '') === 'Status'])>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $row['label'] }}</dt>
                        <dd class="mt-1.5">
                            @if (isset($row['badge']))
                                <span class="badge {{ $row['badge'] }}">{{ $row['value'] }}</span>
                            @else
                                <span @class([
                                    'text-sm font-medium text-slate-900',
                                    'font-mono tracking-wide' => $row['mono'] ?? false,
                                ])>{{ $row['value'] }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if ($slip['applicationFee'] > 0)
                <div class="border-t border-amber-200 bg-amber-50 px-6 py-5">
                    <p class="text-sm font-semibold text-amber-900">
                        Application fee due: {{ $slip['currency'] }}{{ number_format($slip['applicationFee'], 2) }}
                    </p>
                    <p class="mt-1 text-sm text-amber-800">
                        Payable at the school bursary. Bring this slip as your reference.
                        Your examination slip is issued once payment is confirmed.
                    </p>
                </div>
            @endif

            <div class="border-t border-slate-200 bg-slate-50/70 px-6 py-5">
                <p class="text-xs leading-relaxed text-slate-500">
                    This is a computer-generated slip. Your registration number is permanent —
                    it stays with you for life and is how the school identifies your application.
                    Once you are admitted it is complemented by an admission number in the form
                    <span class="font-mono font-medium text-slate-700">{{ $slip['school']['name'] ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $slip['school']['name']), 0, 3)) : 'SAC' }}/{{ now()->year }}/001</span>.
                </p>
            </div>
        </div>

        {{-- ================= Actions ================= --}}
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <button type="button" onclick="window.print()" class="btn-primary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Z"/>
                </svg>
                Print this slip
            </button>

            <a href="{{ route('public.status') }}?registration_number={{ urlencode($applicant->registration_number) }}"
               class="btn-secondary">
                Check admission status
            </a>

            <a href="{{ route('home') }}" class="btn-ghost">Back to home</a>
        </div>
    </div>
</div>
@endsection
