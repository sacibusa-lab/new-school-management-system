@extends('layouts.admin')

@section('title', $invoice->invoice_number)
@section('subtitle', 'Fees & Payments')

@section('actions')
    <a href="{{ route('admin.invoices.index') }}" class="btn-secondary btn-sm">
        <x-nav-icon name="receipt" class="h-3.5 w-3.5" />
        All invoices
    </a>
@endsection

@section('content')

{{--
    One bill, read from the top down the way a parent reads it: who it is for, what
    it is made of, what has been paid, and what is left. The payment form sits under
    the ledger rather than beside it, so the balance a clerk is about to reduce is
    the last thing above the box they type into.

    The money columns are never typed into. `Invoice::recalculate()` re-derives them
    from the items and the payments, and that is called by the controller after every
    change — so the figures here cannot drift from the ledger beneath them.
--}}
@php
    $recorded = $invoice->payments->where('status', \App\Enums\PaymentStatus::Successful->value);
    $reversed = $invoice->payments->where('status', \App\Enums\PaymentStatus::Reversed->value);
@endphp

{{-- ================= Who and what ================= --}}
<div class="card-pad">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="font-mono text-lg font-semibold text-ink">{{ $invoice->invoice_number }}</h2>
                <x-status-pill :status="$invoice->status" />
            </div>

            <p class="mt-2 text-sm text-ink-soft">
                {{ $invoice->student?->full_name }}
                <span class="text-muted">
                    &middot; {{ $invoice->student?->student_number }}
                    @if ($invoice->student?->schoolClass)
                        &middot; {{ $invoice->student->schoolClass->name }}
                    @endif
                </span>
            </p>

            <p class="mt-1 text-xs text-muted">
                {{ $invoice->academicSession?->name }}
                &middot; {{ $invoice->term?->name ?? 'whole session' }}
                @if ($invoice->issued_at)
                    &middot; raised {{ $invoice->issued_at->format('d M Y') }}
                @endif
                @if ($invoice->feeStructure)
                    &middot; from {{ $invoice->feeStructure->name }}
                @endif
            </p>
        </div>

        <div class="text-right">
            <p class="eyebrow">Balance outstanding</p>
            <p class="mt-1 font-display text-2xl font-semibold {{ (float) $invoice->balance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">
                {{ $currency }}{{ number_format((float) $invoice->balance, 2) }}
            </p>
            <p class="mt-1 text-xs text-muted">
                @if ($invoice->due_date)
                    Due {{ $invoice->due_date->format('d M Y') }}
                @else
                    No due date set
                @endif
            </p>
        </div>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-12">

    <div class="space-y-6 lg:col-span-7">

        {{-- ================= What the bill is made of ================= --}}
        <div class="card overflow-hidden">
            <div class="border-b border-line bg-surface-2 px-5 py-4">
                <p class="font-display text-base font-semibold text-ink">What is being charged</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="border-b border-r border-line p-3">Description</th>
                            <th class="w-32 border-b border-r border-line p-3">Category</th>
                            <th class="w-32 border-b border-r border-line p-3 text-right">Amount</th>
                            <th class="w-32 border-b border-line p-3 text-right">Paid against</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-line text-ink-soft">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="border-r border-line p-3 align-middle">
                                    <span class="block font-medium text-ink">{{ $item->description }}</span>
                                    @unless ($item->is_compulsory)
                                        <span class="mt-0.5 block text-xs text-muted">Optional charge</span>
                                    @endunless
                                </td>

                                <td class="border-r border-line p-3 align-middle text-muted">
                                    {{ $item->category?->name ?? '—' }}
                                </td>

                                <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                                    {{ $currency }}{{ number_format((float) $item->amount, 2) }}
                                </td>

                                <td class="p-3 text-right align-middle font-mono text-xs">
                                    {{ $currency }}{{ number_format((float) $item->amount_paid, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot class="text-ink">
                        <tr class="border-t border-line">
                            <td class="p-3 text-right text-muted" colspan="2">Subtotal</td>
                            <td class="border-r border-line p-3 text-right font-mono text-xs" colspan="2">
                                {{ $currency }}{{ number_format((float) $invoice->subtotal, 2) }}
                            </td>
                        </tr>

                        @if ((float) $invoice->discount > 0)
                            <tr>
                                <td class="p-3 text-right text-muted" colspan="2">Discount</td>
                                <td class="border-r border-line p-3 text-right font-mono text-xs" colspan="2">
                                    &minus;{{ $currency }}{{ number_format((float) $invoice->discount, 2) }}
                                </td>
                            </tr>
                        @endif

                        <tr class="bg-surface-2 font-semibold">
                            <td class="border-t border-line p-3 text-right" colspan="2">Total</td>
                            <td class="border-t border-r border-line p-3 text-right font-mono text-xs" colspan="2">
                                {{ $currency }}{{ number_format((float) $invoice->total, 2) }}
                            </td>
                        </tr>

                        <tr>
                            <td class="p-3 text-right text-muted" colspan="2">Paid so far</td>
                            <td class="border-r border-line p-3 text-right font-mono text-xs" colspan="2">
                                {{ $currency }}{{ number_format((float) $invoice->amount_paid, 2) }}
                            </td>
                        </tr>

                        <tr class="bg-surface-2 font-semibold">
                            <td class="border-t border-line p-3 text-right" colspan="2">Balance</td>
                            <td class="border-t border-r border-line p-3 text-right font-mono text-xs" colspan="2">
                                {{ $currency }}{{ number_format((float) $invoice->balance, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- ================= What has been paid ================= --}}
        <div class="card overflow-hidden" x-data="{ reversing: null }">
            <div class="border-b border-line bg-surface-2 px-5 py-4">
                <p class="font-display text-base font-semibold text-ink">Receipts</p>
                <p class="mt-0.5 text-xs text-muted">
                    {{ $recorded->count() }} {{ Str::plural('payment', $recorded->count()) }}
                    {{ $reversed->isNotEmpty() ? '· '.$reversed->count().' reversed' : '' }}
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="border-b border-r border-line p-3">Receipt</th>
                            <th class="w-32 border-b border-r border-line p-3">Date</th>
                            <th class="w-40 border-b border-r border-line p-3">Method</th>
                            <th class="w-32 border-b border-r border-line p-3 text-right">Amount</th>
                            <th class="w-40 border-b border-r border-line p-3 text-center">Status</th>
                            <th class="w-28 border-b border-line p-3 text-center">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-line text-ink-soft">
                        @forelse ($invoice->payments->sortByDesc('paid_at') as $payment)
                            <tr class="transition-colors hover:bg-surface-3/60">
                                <td class="border-r border-line p-3 align-middle">
                                    <a href="{{ route('admin.invoices.receipt', [$invoice, $payment]) }}"
                                       class="font-mono text-xs font-semibold text-ink hover:underline">
                                        {{ $payment->receipt_number }}
                                    </a>
                                    @if ($payment->reference)
                                        <span class="mt-0.5 block text-[11px] text-muted">{{ $payment->reference }}</span>
                                    @endif
                                </td>

                                <td class="border-r border-line p-3 align-middle">
                                    {{ $payment->paid_at?->format('d M Y') }}
                                </td>

                                <td class="border-r border-line p-3 align-middle">
                                    {{ $payment->methodLabel() }}
                                    @if ($payment->recorder)
                                        <span class="mt-0.5 block text-xs text-muted">taken by {{ $payment->recorder->name }}</span>
                                    @endif
                                </td>

                                <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                                    {{ $currency }}{{ number_format((float) $payment->amount, 2) }}
                                </td>

                                <td class="border-r border-line p-3 text-center align-middle">
                                    <x-status-pill :status="$payment->status" />
                                </td>

                                <td class="p-3 text-center align-middle">
                                    @if ($payment->status === \App\Enums\PaymentStatus::Successful && auth()->user()?->can('payments.void'))
                                        <button type="button"
                                                class="btn-ghost btn-sm text-rose-600 dark:text-rose-400"
                                                @click="reversing = reversing === {{ $payment->id }} ? null : {{ $payment->id }}">
                                            Reverse
                                        </button>
                                    @else
                                        <span class="text-xs text-muted">—</span>
                                    @endif
                                </td>
                            </tr>

                            {{-- Reversing is not deleting: the receipt keeps its number and
                                 stays on the ledger, marked, so the gap in the books is
                                 explained rather than hidden. A reason is required. --}}
                            @if ($payment->status === \App\Enums\PaymentStatus::Successful && auth()->user()?->can('payments.void'))
                                <tr x-show="reversing === {{ $payment->id }}" x-cloak>
                                    <td colspan="6" class="border-t border-line bg-surface-2 p-5">
                                        <form method="POST" action="{{ route('admin.payments.reverse', $payment) }}">
                                            @csrf

                                            <p class="text-sm font-medium text-ink">
                                                Reverse {{ $payment->receipt_number }} ({{ $currency }}{{ number_format((float) $payment->amount, 2) }})
                                            </p>
                                            <p class="mt-1 text-xs text-muted">
                                                The amount goes back onto the balance. The receipt is kept and marked reversed.
                                            </p>

                                            <div class="mt-4">
                                                <label for="reason_{{ $payment->id }}" class="label">Why it is being reversed</label>
                                                <input id="reason_{{ $payment->id }}" name="reason" type="text" required
                                                       maxlength="255" class="input"
                                                       placeholder="e.g. cheque returned unpaid">
                                            </div>

                                            <div class="mt-4 flex items-center gap-2">
                                                <button type="button" class="btn-ghost btn-sm" @click="reversing = null">Cancel</button>
                                                <button type="submit" class="btn-danger btn-sm">Reverse payment</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="p-5">
                                    <x-empty-state
                                        icon="cash"
                                        title="Nothing has been paid against this invoice"
                                        description="Record what a parent hands over and a numbered receipt is issued against it." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ================= Take the money ================= --}}
    <div class="space-y-6 lg:col-span-5">
        @can('payments.record')
            <div class="card-pad">
                <h2 class="text-base font-semibold text-ink">Record a payment</h2>

                @if ((float) $invoice->balance <= 0)
                    <p class="mt-2 text-sm text-ink-soft">
                        This invoice is settled in full. Nothing more can be recorded against it.
                    </p>
                @else
                    <p class="mt-2 text-sm text-ink-soft">
                        A numbered receipt is issued as soon as this is saved.
                    </p>

                    <form method="POST" action="{{ route('admin.invoices.payments.store', $invoice) }}" class="mt-5">
                        @csrf

                        <div class="space-y-5">
                            <x-field name="amount" label="Amount received" type="number" required step="0.01" min="1"
                                     :value="number_format((float) $invoice->balance, 2, '.', '')"
                                     hint="Starts at the full balance. Change it for a part payment." />

                            <x-field name="method" label="How it was paid" type="select" required
                                     :value="'cash'"
                                     :options="[
                                         'cash' => 'Cash',
                                         'bank_transfer' => 'Bank transfer',
                                         'card' => 'Card',
                                         'gateway' => 'Online payment',
                                         'cheque' => 'Cheque',
                                     ]" />

                            <x-field name="reference" label="Reference"
                                     hint="Teller slip, transfer reference or cheque number." />

                            <x-field name="paid_at" label="Date paid" type="date" :value="now()->toDateString()" />

                            <div>
                                <label for="payment_notes" class="label">Notes</label>
                                <textarea id="payment_notes" name="notes" rows="2" class="input"></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary mt-5 w-full">
                            <x-nav-icon name="cash" class="h-4 w-4" />
                            Record and issue receipt
                        </button>
                    </form>
                @endif
            </div>
        @endcan

        {{--
            The account number is the one thing on this page that a parent telephoning
            the office needs: once they have it, every transfer after that finds its
            own way to the right child.
        --}}
        @if ($invoice->student)
            <div class="card-pad">
                <h2 class="text-base font-semibold text-ink">Paying into the bank</h2>

                @if ($virtualAccount)
                    <p class="mt-2 text-sm text-muted">
                        Money transferred into this account is credited to
                        {{ $invoice->student->first_name }}'s oldest unpaid bill first.
                    </p>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="eyebrow">Bank</dt>
                            <dd class="mt-1 font-medium text-ink">{{ $virtualAccount->bank_name }}</dd>
                        </div>

                        <div x-data="{ copied: false }">
                            <dt class="eyebrow">Account number</dt>
                            <dd class="mt-1">
                                <button type="button"
                                        class="inline-flex items-center gap-2 font-mono text-lg font-semibold text-ink"
                                        title="Copy the account number"
                                        @click="navigator.clipboard.writeText('{{ $virtualAccount->account_number }}'); copied = true; setTimeout(() => copied = false, 1500)">
                                    {{ $virtualAccount->account_number }}
                                    <x-nav-icon name="copy" class="h-4 w-4 text-muted" />
                                </button>
                                <span x-show="copied" x-cloak class="ml-1 text-xs text-emerald-600 dark:text-emerald-400">Copied</span>
                            </dd>
                        </div>

                        <div>
                            <dt class="eyebrow">Account name</dt>
                            <dd class="mt-1 text-ink">{{ $virtualAccount->account_name }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-muted">
                        No account number yet. One is opened against the child, and every
                        transfer into it is matched to them by name — nobody has to quote a
                        reference, and the office does not have to reconcile a bank statement.
                    </p>

                    @can('fees.manage')
                        <form method="POST" action="{{ route('admin.fees.virtual-account', $invoice->student) }}" class="mt-4">
                            @csrf

                            <button type="submit" class="btn-primary w-full">
                                <x-nav-icon name="key" class="h-4 w-4" />
                                Open an account number
                            </button>
                        </form>
                    @endcan
                @endif
            </div>
        @endif

        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">The student's fees</h2>
            <p class="mt-2 text-sm text-muted">
                Everything this student owes, on one page, with a printable statement of
                account for a parent who asks for one.
            </p>

            @if ($invoice->student)
                <a href="{{ route('admin.students.show', $invoice->student) }}" class="btn-secondary mt-4 w-full">
                    <x-nav-icon name="users" class="h-4 w-4" />
                    Open {{ $invoice->student->first_name }}'s record
                </a>
            @endif
        </div>
    </div>
</div>

@endsection
