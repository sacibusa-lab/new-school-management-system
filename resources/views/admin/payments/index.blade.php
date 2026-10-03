@extends('layouts.admin')

@section('title', 'Payments')
@section('subtitle', 'Fees')

@section('content')

{{--
    Money received, newest first — the day book the bursar reads at closing time.

    A receipt is a link, not a row to edit. Nothing on this page changes a payment:
    the only thing that can happen to one after it is taken is that it is reversed,
    and that is done on the invoice it was taken against, where the balance it
    changes is on the same screen.
--}}
<div class="grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Receipted today" icon="cash" tone="emerald"
                 :value="$currency.number_format($todayTotal, 2)"
                 hint="Successful payments dated today" />

    <x-stat-card label="Payments matching" icon="list" tone="brand"
                 :value="$payments->total()"
                 hint="Across every page" />

    <x-stat-card label="On this page" icon="receipt" tone="slate"
                 :value="$currency.number_format($payments->getCollection()->where('status', \App\Enums\PaymentStatus::Successful->value)->sum('amount'), 2)"
                 hint="Successful only" />
</div>

{{-- ================= Filters ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-field name="q" label="Find" :value="request('q')"
                 placeholder="Receipt no., reference or surname" />

        <x-field name="method" label="How it was paid" type="select"
                 placeholder-option="Any method"
                 :value="request('method')"
                 :options="$methods" />

        <x-field name="status" label="Status" type="select"
                 placeholder-option="Any status"
                 :value="request('status')"
                 :options="$statuses" />

        <button type="submit" class="btn-primary w-full">Filter</button>
    </div>
</form>

{{-- ================= The day book ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Payments</p>
        <p class="mt-0.5 text-xs text-muted">
            A reversed payment stays on this list. It is marked, not removed — the receipt
            number was given to a parent and the gap in the numbers has to be explained.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">Receipt</th>
                    <th class="w-32 border-b border-r border-line p-3">Date</th>
                    <th class="border-b border-r border-line p-3">Student</th>
                    <th class="w-40 border-b border-r border-line p-3">Invoice</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Amount</th>
                    <th class="w-40 border-b border-r border-line p-3">Method</th>
                    <th class="w-28 border-b border-r border-line p-3 text-center">Status</th>
                    <th class="w-24 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($payments as $payment)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle">
                            @if ($payment->invoice)
                                <a href="{{ route('admin.invoices.receipt', [$payment->invoice, $payment]) }}"
                                   class="font-mono text-xs font-semibold text-ink hover:underline">
                                    {{ $payment->receipt_number }}
                                </a>
                            @else
                                <span class="font-mono text-xs font-semibold text-ink">{{ $payment->receipt_number }}</span>
                            @endif

                            @if ($payment->reference)
                                <span class="mt-0.5 block text-[11px] text-muted">{{ $payment->reference }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $payment->paid_at?->format('d M Y') }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            <span class="block font-medium text-ink">{{ $payment->student?->full_name }}</span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $payment->student?->student_number }}
                                @if ($payment->student?->schoolClass)
                                    &middot; {{ $payment->student->schoolClass->name }}
                                @endif
                            </span>
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            @if ($payment->invoice)
                                <a href="{{ route('admin.invoices.show', $payment->invoice) }}"
                                   class="font-mono text-xs hover:underline">{{ $payment->invoice->invoice_number }}</a>
                            @else
                                <span class="text-xs text-muted">Not against an invoice</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                            {{ $currency }}{{ number_format((float) $payment->amount, 2) }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $payment->methodLabel() }}
                            @if ($payment->recorder)
                                <span class="mt-0.5 block text-xs text-muted">by {{ $payment->recorder->name }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            <x-status-pill :status="$payment->status" />
                        </td>

                        <td class="p-3 text-center align-middle">
                            @if ($payment->invoice)
                                <a href="{{ route('admin.invoices.receipt', [$payment->invoice, $payment]) }}"
                                   class="btn-secondary btn-sm" title="Open the receipt">
                                    <x-nav-icon name="printer" class="h-3.5 w-3.5" />
                                    <span class="sr-only">Receipt</span>
                                </a>
                            @else
                                <span class="text-xs text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="p-5">
                            <x-empty-state
                                icon="cash"
                                title="No payments match"
                                description="Money is recorded against the invoice it settles, so the balance it reduces and the receipt it issues are on one screen." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($payments->hasPages())
        <div class="border-t border-line px-5 py-4">
            {{ $payments->links() }}
        </div>
    @endif
</div>

@endsection
