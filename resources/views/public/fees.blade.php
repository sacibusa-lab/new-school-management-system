@extends('layouts.public')

@section('title', 'Fee status')

@section('content')
<div class="bg-surface-2 py-12">
    <div class="section max-w-5xl">

        <div class="mx-auto max-w-2xl text-center">
            <span class="eyebrow">School fees</span>
            <h1 class="mt-5 font-display text-3xl font-semibold text-ink sm:text-4xl">
                Your fee statement
            </h1>
            <p class="mt-4 text-ink-soft">
                See what has been billed, what you have paid, and what is still outstanding —
                up to date, at any time.
            </p>
        </div>

        <form method="GET" class="card-pad mx-auto mt-8 max-w-2xl">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="student_number" label="Admission number" required
                         placeholder="SAC/{{ now()->year }}/001"
                         hint="Issued when your child was admitted. Not the registration number (SAC-00001)."
                         :value="request('student_number')" />

                <x-field name="surname" label="Surname" required
                         placeholder="Okafor"
                         :value="request('surname')" />
            </div>

            <button type="submit" class="btn-primary mt-5 w-full">View statement</button>
        </form>

        @if ($searched)
            <div class="mt-8">
                @if (! $student)
                    <x-alert tone="danger" title="No match found">
                        We could not find a student with that admission number and surname.
                        Check the number carefully — it looks like
                        <span class="font-mono">SAC/{{ now()->year }}/001</span>.
                    </x-alert>

                @elseif (! $student->fees_portal_enabled)
                    <x-alert tone="warning" title="Fee portal not enabled">
                        The fee statement for {{ $student->full_name }} is not available online yet.
                        Please contact the bursary.
                    </x-alert>

                @else
                    @php
                        $billed = $student->totalBilled();
                        $paid = $student->totalPaid();
                        $balance = $student->outstandingBalance();
                    @endphp

                    {{-- ================= Header ================= --}}
                    <div class="card overflow-hidden">
                        <div class="border-b border-line bg-surface-2 p-5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-900 font-display text-base font-semibold text-gold-300">
                                        {{ $student->initials }}
                                    </span>
                                    <div>
                                        <p class="font-display text-lg font-semibold text-ink">{{ $student->full_name }}</p>
                                        <p class="font-mono text-sm text-muted">{{ $student->student_number }}</p>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <p class="text-sm text-ink-soft">{{ $student->schoolClass?->name ?? $student->level?->name }}</p>
                                    <p class="text-xs text-muted">{{ $student->academicSession?->name }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- ================= Money ================= --}}
                        <div class="grid divide-y divide-line sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="p-5 text-center sm:p-6">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">Total billed</p>
                                <p class="mt-2 font-display text-2xl font-semibold text-ink">
                                    {{ $currency }}{{ number_format($billed, 2) }}
                                </p>
                            </div>

                            <div class="p-5 text-center sm:p-6">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">Total paid</p>
                                <p class="mt-2 font-display text-2xl font-semibold text-emerald-700 dark:text-emerald-300">
                                    {{ $currency }}{{ number_format($paid, 2) }}
                                </p>
                            </div>

                            <div @class(['p-5 text-center sm:p-6', 'bg-rose-50/60' => $balance > 0, 'bg-emerald-50/60' => $balance <= 0])>
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">Outstanding</p>
                                <p @class(['mt-2 font-display text-2xl font-semibold', 'text-rose-700 dark:text-rose-300' => $balance > 0, 'text-emerald-700 dark:text-emerald-300' => $balance <= 0])>
                                    {{ $currency }}{{ number_format($balance, 2) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ================= Invoices ================= --}}
                    <div class="mt-6 space-y-5">
                        @foreach ($invoices as $invoice)
                            <div class="card overflow-hidden">
                                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line bg-surface-2 px-5 py-4">
                                    <div>
                                        <p class="font-mono text-sm font-semibold text-ink">{{ $invoice->invoice_number }}</p>
                                        <p class="mt-0.5 text-xs text-muted">
                                            {{ $invoice->term?->name ?? 'Whole session' }}
                                            · issued {{ $invoice->issued_at?->format('j M Y') }}
                                            @if ($invoice->due_date) · due {{ $invoice->due_date->format('j M Y') }} @endif
                                        </p>
                                    </div>

                                    <x-status-pill :status="$invoice->status" />
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th class="text-right">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($invoice->items as $item)
                                                <tr>
                                                    <td>{{ $item->description }}</td>
                                                    <td class="text-right font-medium">{{ $currency }}{{ number_format((float) $item->amount, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t-2 border-line bg-surface-2">
                                                <td class="px-4 py-3 text-sm font-semibold text-ink">Total</td>
                                                <td class="px-4 py-3 text-right text-sm font-semibold text-ink">
                                                    {{ $currency }}{{ number_format((float) $invoice->total, 2) }}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="px-4 py-2 text-sm text-ink-soft">Paid</td>
                                                <td class="px-4 py-2 text-right text-sm text-emerald-700 dark:text-emerald-300">
                                                    − {{ $currency }}{{ number_format((float) $invoice->amount_paid, 2) }}
                                                </td>
                                            </tr>
                                            <tr class="bg-surface-2">
                                                <td class="px-4 py-3 text-sm font-semibold text-ink">Balance due</td>
                                                <td @class(['px-4 py-3 text-right text-sm font-semibold', 'text-rose-700 dark:text-rose-300' => (float) $invoice->balance > 0, 'text-emerald-700 dark:text-emerald-300' => (float) $invoice->balance <= 0])>
                                                    {{ $currency }}{{ number_format((float) $invoice->balance, 2) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        @endforeach

                        @if ($invoices->isEmpty())
                            <x-alert tone="info">
                                No invoice has been raised for {{ $student->full_name }} yet.
                            </x-alert>
                        @endif
                    </div>

                    {{-- ================= Receipts ================= --}}
                    @if ($payments->isNotEmpty())
                        <div class="card mt-6">
                            <div class="panel-header">
                                <p class="panel-title">Payment history</p>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Receipt</th>
                                            <th>Paid on</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($payments as $payment)
                                            <tr>
                                                <td class="font-mono text-xs font-medium text-ink">{{ $payment->receipt_number }}</td>
                                                <td class="text-sm">{{ $payment->paid_at?->format('j M Y') ?? '—' }}</td>
                                                <td class="text-sm">{{ $payment->methodLabel() }}</td>
                                                <td class="font-mono text-xs text-muted">{{ $payment->reference ?? '—' }}</td>
                                                <td class="text-right font-medium">{{ $currency }}{{ number_format((float) $payment->amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    {{-- ================= How to pay ================= --}}
                    <div class="card-pad mt-6 bg-brand-50/60">
                        <h3 class="text-sm font-semibold text-brand-900 dark:text-brand-100">How to pay</h3>
                        <p class="mt-2 text-sm text-brand-800 dark:text-brand-200">
                            Payments are recorded by the school bursary. Bring this statement (or your
                            admission number) to the bursary, and a receipt will be issued and appear on
                            this page immediately.
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
