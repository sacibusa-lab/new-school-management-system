@extends('layouts.admin')

@section('title', 'Invoices')
@section('subtitle', 'Fees')

@section('content')

{{--
    Every bill the school has raised, newest first, with the three numbers that
    matter at the top: what has been billed this session, what has come in, and
    what is still owed.

    The totals are summed in the database rather than from the page of rows below
    them — a page holds twenty-five of four hundred invoices, and a collection
    figure that only counted the page would be wrong in the one place it is most
    read.
--}}
@php
    $billed = (float) ($totals->billed ?? 0);
    $collected = (float) ($totals->collected ?? 0);
    $outstanding = (float) ($totals->outstanding ?? 0);
    $rate = $billed > 0 ? (int) round($collected / $billed * 100) : 0;
@endphp

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <x-stat-card label="Billed" icon="receipt" tone="slate"
                 :value="$currency.number_format($billed, 2)"
                 :hint="$session ? $session->name : 'All sessions'" />

    <x-stat-card label="Collected" icon="cash" tone="emerald"
                 :value="$currency.number_format($collected, 2)"
                 hint="{{ $rate }}% of what was billed" />

    <x-stat-card label="Outstanding" icon="clock" tone="rose"
                 :value="$currency.number_format($outstanding, 2)"
                 hint="Still to come in" />

    <x-stat-card label="Invoices matching" icon="list" tone="brand"
                 :value="$invoices->total()"
                 hint="Across every page" />
</div>

{{-- ================= Filters ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-field name="q" label="Find" :value="$invoices->getCollection()->isEmpty() ? null : request('q')"
                 placeholder="Invoice no., name or admission no." />

        <x-field name="status" label="Status" type="select"
                 placeholder-option="Any status"
                 :value="request('status')"
                 :options="$statuses" />

        <x-field name="session" label="Session" type="select"
                 placeholder-option="Any session"
                 :value="request('session')"
                 :options="$sessions->pluck('name', 'id')->all()" />

        <x-field name="class" label="Class" type="select"
                 placeholder-option="Any class"
                 :value="request('class')"
                 :options="$classes->mapWithKeys(fn ($class) => [$class->id => $class->name])->all()" />

        <button type="submit" class="btn-primary w-full">Filter</button>
    </div>
</form>

{{-- ================= The register ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Invoices</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">Invoice</th>
                    <th class="border-b border-r border-line p-3">Student</th>
                    <th class="w-32 border-b border-r border-line p-3">Term</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Total</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Paid</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Balance</th>
                    <th class="w-32 border-b border-r border-line p-3">Due</th>
                    <th class="w-28 border-b border-r border-line p-3 text-center">Status</th>
                    <th class="w-20 border-b border-line p-3 text-center"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($invoices as $invoice)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle">
                            <a href="{{ route('admin.invoices.show', $invoice) }}"
                               class="font-mono text-xs font-semibold text-ink hover:underline">
                                {{ $invoice->invoice_number }}
                            </a>
                            @if ($invoice->is_auto_generated)
                                <span class="mt-0.5 block text-[11px] text-muted">raised by billing</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            <span class="block font-medium text-ink">{{ $invoice->student?->full_name }}</span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $invoice->student?->student_number }}
                                @if ($invoice->student?->schoolClass)
                                    &middot; {{ $invoice->student->schoolClass->name }}
                                @endif
                            </span>
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $invoice->term?->name ?? '—' }}
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                            {{ $currency }}{{ number_format((float) $invoice->total, 2) }}
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                            {{ $currency }}{{ number_format((float) $invoice->amount_paid, 2) }}
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                            {{ $currency }}{{ number_format((float) $invoice->balance, 2) }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            <x-status-pill :status="$invoice->status" />
                        </td>

                        <td class="p-3 text-center align-middle">
                            <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn-secondary btn-sm">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="p-5">
                            <x-empty-state
                                icon="receipt"
                                title="No invoices match"
                                description="Invoices are raised from a fee structure — open one and bill the students it covers — or raised for a single student here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($invoices->hasPages())
        <div class="border-t border-line px-5 py-4">
            {{ $invoices->links() }}
        </div>
    @endif
</div>

@can('fees.invoice')
    {{-- ================= One student, one bill ================= --}}
    {{--
        The bulk path is the fee structure screen; this is for the exceptions — a
        child who transfers in during the term, or one whose bill was cancelled and
        has to be raised again. The structure and the term are worked out from the
        student, so there is only one thing to choose.
    --}}
    <div class="card-pad mt-6 lg:max-w-3xl" x-data="{ open: false }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">Raise an invoice for one student</h2>
                <p class="mt-1 text-sm text-muted">
                    For a late arrival or a bill that was cancelled. Billing a whole year group is
                    done from its fee structure.
                </p>
            </div>

            <button type="button" class="btn-secondary btn-sm" @click="open = ! open"
                    x-text="open ? 'Close' : 'Choose a student'"></button>
        </div>

        <form method="POST" action="{{ route('admin.invoices.store') }}" class="mt-5" x-show="open" x-cloak>
            @csrf

            <x-field name="student_id" label="Student" type="select" required
                     :options="$students->mapWithKeys(fn ($student) => [$student->id => $student->student_number.' — '.$student->full_name])->all()"
                     hint="The structure that applies to their year group and term is used. If none applies, nothing is raised and the reason is shown." />

            <button type="submit" class="btn-primary mt-4">Raise invoice</button>
        </form>
    </div>
@endcan

@endsection
