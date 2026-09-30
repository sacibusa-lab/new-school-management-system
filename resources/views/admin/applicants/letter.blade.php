@extends('layouts.admin')

@section('title', 'Admission letter')
@section('subtitle', $applicant->full_name . ' · ' . $applicant->registration_number)

@section('actions')
    <a href="{{ route('admin.applicants.letter.pdf', $applicant) }}" class="btn-secondary btn-sm">Download PDF</a>
    <button type="button" onclick="window.print()" class="btn-primary btn-sm">Print this letter</button>
    <a href="{{ route('admin.applicants.show', $applicant) }}" class="btn-ghost btn-sm">Back to applicant</a>
@endsection

@push('head')
    {{-- Without this the sidebar and toolbar print on top of the letter. --}}
    <style>
        @media print {
            aside, header, .print-hide { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
        }
    </style>
@endpush

@section('content')

<div class="mx-auto max-w-3xl">

    <x-alert tone="info" class="mb-6 print:hidden">
        Check the wording before printing. It comes from
        <a href="{{ route('admin.settings.index') }}" class="underline underline-offset-2">Settings</a>, and the
        placeholders below have already been filled in with this applicant's details.
    </x-alert>

    {{-- ================= The letter ================= --}}
    <article class="card p-8 sm:p-12 print:border-0 print:p-0 print:shadow-none">

        {{-- Letterhead --}}
        <header class="border-b border-slate-300 pb-6 text-center">
            @if ($letterhead['image'])
                {{-- The school's own letterhead, as the office printed it, instead of
                     the name and address underneath it typed out again. --}}
                <img src="{{ asset('storage/' . $letterhead['image']) }}"
                     alt="{{ $letterhead['name'] }}"
                     class="mx-auto max-h-28 max-w-full object-contain">
            @else
                <h1 class="font-display text-2xl font-semibold tracking-tight text-slate-900">
                    {{ $letterhead['name'] }}
                </h1>

                @if ($letterhead['address'])
                    <p class="mt-1 text-sm text-slate-600">{{ $letterhead['address'] }}</p>
                @endif

                @if ($letterhead['phone'] || $letterhead['email'])
                    <p class="mt-1 text-sm text-slate-600">
                        {{ collect([$letterhead['phone'], $letterhead['email']])->filter()->implode(' · ') }}
                    </p>
                @endif
            @endif
        </header>

        {{-- Reference block, right aligned the way offices print these --}}
        <div class="mt-6 text-sm text-slate-700">
            <p class="font-semibold text-slate-900">{{ $letter['title'] }}</p>
            <p class="mt-1">Our ref: <span class="font-mono">{{ $reference }}</span></p>
            <p>Date: {{ $issuedOn->format('j F, Y') }}</p>
        </div>

        {{-- Body --}}
        <div class="mt-8 space-y-4 text-[15px] leading-relaxed text-slate-800">
            {!! nl2br(e($letter['body'])) !!}
        </div>

        {{-- Quick facts, so the parent can verify the details at a glance --}}
        <dl class="mt-10 grid gap-x-8 gap-y-3 border-t border-slate-200 pt-6 text-sm sm:grid-cols-2">
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Registration number</dt>
                <dd class="font-mono font-medium text-slate-900">{{ $reference }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Admission number</dt>
                <dd class="font-mono font-medium text-slate-900">{{ $studentNumber ?? '—' }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Class admitted into</dt>
                <dd class="font-medium text-slate-900">{{ $level ?? '—' }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Session</dt>
                <dd class="font-medium text-slate-900">{{ $session ?? '—' }}</dd>
            </div>

            @if ($average !== null)
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Entrance average</dt>
                    <dd class="font-medium text-slate-900">{{ number_format((float) $average, 2) }}%</dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Cutoff mark</dt>
                    <dd class="font-medium text-slate-900">
                        {{ $cutoff !== null ? number_format((float) $cutoff, 2) . '%' : '—' }}
                    </dd>
                </div>
            @endif

            @if ($position)
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Position on merit list</dt>
                    <dd class="font-medium text-slate-900">{{ $position }}</dd>
                </div>
            @endif
        </dl>

        {{-- Signature --}}
        <div class="mt-12">
            @if ($signature)
                {{-- Decorative: the name under it says who signed. --}}
                <img src="{{ asset('storage/' . $signature) }}" alt="" class="mb-1 h-14 w-auto">
            @endif

            @if ($letter['signatory'])
                <p class="font-display text-lg text-slate-900">{{ $letter['signatory'] }}</p>
            @endif
            <p class="mt-4 w-56 border-t border-slate-400 pt-1 text-sm text-slate-600">
                {{ $letter['signatoryTitle'] }}
            </p>
        </div>

        {{-- The note is printed small at the foot of the page --}}
        <p class="mt-10 border-t border-dashed border-slate-300 pt-4 text-xs text-slate-500">
            {{ $note }}
        </p>
    </article>

    {{-- ================= Placeholder legend ================= --}}
    <details class="card-pad mt-6 print:hidden">
        <summary class="cursor-pointer text-sm font-medium text-slate-700">
            Values used on this letter
        </summary>

        <div class="mt-4 grid gap-x-8 gap-y-2 text-xs sm:grid-cols-2">
            @foreach ($letterPlaceholders as $key => $value)
                <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
                    <span class="font-mono text-slate-500">{{ '{' . $key . '}' }}</span>
                    <span class="text-right font-medium text-slate-800">{{ $value }}</span>
                </div>
            @endforeach
        </div>
    </details>
</div>

@endsection
