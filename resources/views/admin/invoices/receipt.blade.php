<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <title>Receipt {{ $payment->receipt_number }} · {{ \App\Models\Setting::get('school_name', config('app.name')) }}</title>

    @vite(['resources/css/app.css'])

    {{--
        A receipt is a piece of paper with a number on it. It is the one document in
        this module that leaves the building, so it is drawn on its own — no sidebar,
        no controls — and the button that prints it is the only thing on the page
        that does not appear on the paper.
    --}}
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .sheet { box-shadow: none !important; border: 0 !important; margin: 0 !important; }
            @page { margin: 12mm; }
        }
    </style>
</head>

@php
    $schoolName = \App\Models\Setting::get('school_name', config('app.name'));
    $logo = \App\Models\Setting::get('school_logo');
    $recorded = $payment->status === \App\Enums\PaymentStatus::Successful;
@endphp

<body class="min-h-full bg-surface-2 p-4 sm:p-8">

<div class="mx-auto max-w-2xl">

    <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn-secondary btn-sm">
            <x-nav-icon name="receipt" class="h-3.5 w-3.5" />
            Back to the invoice
        </a>

        <button type="button" class="btn-primary btn-sm" onclick="window.print()">
            <x-nav-icon name="printer" class="h-3.5 w-3.5" />
            Print this receipt
        </button>
    </div>

    {{-- ================= The paper ================= --}}
    <div class="sheet rounded-2xl border border-line bg-white p-8 text-slate-900 shadow-sm dark:border-line">

        <div class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-5">
            <div class="flex items-start gap-4">
                @if ($logo)
                    <img src="{{ asset('storage/'.$logo) }}" alt="" class="h-16 w-16 shrink-0 object-contain">
                @endif

                <div>
                    <p class="font-display text-xl font-semibold uppercase tracking-wide">{{ $schoolName }}</p>
                    @if (\App\Models\Setting::get('contact_address'))
                        <p class="mt-1 text-xs leading-relaxed">{{ \App\Models\Setting::get('contact_address') }}</p>
                    @endif
                    <p class="mt-1 text-xs">
                        @if (\App\Models\Setting::get('contact_phone')){{ \App\Models\Setting::get('contact_phone') }}@endif
                        @if (\App\Models\Setting::get('contact_email')) &middot; {{ \App\Models\Setting::get('contact_email') }}@endif
                    </p>
                </div>
            </div>

            <div class="text-right">
                <p class="font-display text-lg font-semibold uppercase tracking-widest">Receipt</p>
                <p class="mt-1 font-mono text-sm font-semibold">{{ $payment->receipt_number }}</p>
                <p class="mt-1 text-xs">{{ $payment->paid_at?->format('d M Y') }}</p>
                @unless ($recorded)
                    <p class="mt-2 inline-block border border-slate-900 px-2 py-0.5 text-[11px] font-semibold uppercase">
                        Reversed
                    </p>
                @endunless
            </div>
        </div>

        {{-- Who it is for, and against what. --}}
        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Received from</p>
                <p class="mt-1 text-sm font-semibold">{{ $invoice->student?->full_name }}</p>
                <p class="text-xs">
                    {{ $invoice->student?->student_number }}
                    @if ($invoice->student?->schoolClass)
                        &middot; {{ $invoice->student->schoolClass->name }}
                    @endif
                </p>
                @if ($invoice->student?->guardian_name)
                    <p class="mt-1 text-xs">Guardian: {{ $invoice->student->guardian_name }}</p>
                @endif
            </div>

            <div class="sm:text-right">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Against</p>
                <p class="mt-1 font-mono text-sm font-semibold">{{ $invoice->invoice_number }}</p>
                <p class="text-xs">
                    {{ $invoice->academicSession?->name }}
                    &middot; {{ $invoice->term?->name ?? 'whole session' }}
                </p>
            </div>
        </div>

        {{-- The money. --}}
        <div class="mt-6 border-t border-slate-300 pt-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Amount received</p>
            <p class="mt-1 font-display text-3xl font-semibold">
                {{ $currency }}{{ number_format((float) $payment->amount, 2) }}
            </p>

            <p class="mt-2 text-xs">
                Paid by {{ $payment->methodLabel() }}
                @if ($payment->reference)
                    &middot; reference {{ $payment->reference }}
                @endif
            </p>
        </div>

        {{-- What the bill looks like now. A receipt that does not say what is left
             leaves a parent asking the office, which is what the receipt was for. --}}
        <div class="mt-6 border-t border-slate-300 pt-5">
            <table class="w-full text-sm">
                <tbody>
                    <tr>
                        <td class="py-1 text-slate-600">Total billed</td>
                        <td class="py-1 text-right font-mono text-xs">{{ $currency }}{{ number_format((float) $invoice->total, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 text-slate-600">Paid to date</td>
                        <td class="py-1 text-right font-mono text-xs">{{ $currency }}{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                    </tr>
                    <tr class="border-t border-slate-300 font-semibold">
                        <td class="pt-2">Balance still owing</td>
                        <td class="pt-2 text-right font-mono text-xs">{{ $currency }}{{ number_format((float) $invoice->balance, 2) }}</td>
                    </tr>
                </tbody>
            </table>

            @if ($invoice->due_date && (float) $invoice->balance > 0)
                <p class="mt-2 text-xs text-slate-600">
                    The balance above is due by {{ $invoice->due_date->format('d M Y') }}.
                </p>
            @endif
        </div>

        {{-- Who took it, and the line for a signature. --}}
        <div class="mt-10 flex items-end justify-between gap-6">
            <div>
                <p class="border-t border-slate-400 pt-1 text-xs font-semibold">
                    {{ $payment->recorder?->name ?? '—' }}
                </p>
                <p class="text-[11px] text-slate-500">Money received by</p>
            </div>

            <div class="text-right">
                <p class="border-t border-slate-400 pt-1 text-xs">Signature</p>
            </div>
        </div>

        <p class="mt-8 border-t border-dashed border-slate-300 pt-3 text-center text-[11px] text-slate-500">
            This receipt is not valid unless it carries the receipt number above. Keep it —
            it is the school's record of the money you have paid.
        </p>
    </div>
</div>

</body>
</html>
