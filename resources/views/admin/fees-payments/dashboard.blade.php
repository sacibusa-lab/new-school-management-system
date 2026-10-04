@extends('layouts.admin')

@section('title', 'Dashboard')
@section('subtitle', 'Fees & Payments')

@section('actions')
    <a href="{{ route('admin.fees-payments.students-hub') }}" class="btn-secondary btn-sm">Students hub</a>
    <a href="{{ route('admin.invoices.index') }}" class="btn-secondary btn-sm">All invoices</a>
@endsection

@section('content')

{{--
    The collection desk's own front page.

    The headline is the session so far, which is the position rather than the news.
    Everything under it is the news: what came in today, where the gaps are class by
    class, how the money arrives, and what the parents are actually paying for.

    The fees section on the main school dashboard answers "are the fees working". This
    one answers "where are they not", which is a different question and needs more than
    four cards to answer.
--}}
<div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
    <p class="max-w-3xl text-sm text-muted">
        How the money is doing{{ $session ? ' for ' . $session->name : '' }}: what has been billed
        and collected, what came in today, and where the gaps are.
    </p>

    <p class="text-xs text-muted">Today is {{ now()->format('l, j F Y') }}.</p>
</div>

@unless ($session)
    <div class="mt-6">
        <x-alert tone="warning">
            No academic session is open, so every figure here is zero. Set the current session
            under Academic Calendar and this page fills in.
        </x-alert>
    </div>
@endunless

{{-- ================= Today ================= --}}
<div class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card
        label="Collected today"
        :value="$school->currency . number_format($today['collected'], 2)"
        :hint="$today['payments'] . ' ' . Str::plural('payment', $today['payments']) . ' recorded'"
        icon="cash"
        tone="emerald"
        :href="route('admin.payments.index')" />

    <x-stat-card
        label="Bills raised today"
        :value="$school->currency . number_format($today['raised'], 2)"
        :hint="$today['bills'] . ' ' . Str::plural('bill', $today['bills']) . ' raised'"
        icon="receipt"
        tone="brand"
        :href="route('admin.invoices.index')" />

    <x-stat-card
        label="This week"
        :value="$school->currency . number_format($today['week'], 2)"
        hint="Since Monday"
        icon="calendar"
        tone="gold"
        :href="route('admin.payments.index')" />

    {{-- Not a fee figure, but a collection rate means nothing without knowing how many
         families are behind it. --}}
    <x-stat-card
        label="On the roll"
        :value="number_format($roll)"
        :hint="'active ' . Str::plural('student', $roll)"
        icon="users"
        tone="slate"
        :href="route('admin.fees-payments.students-hub')" />
</div>

{{-- ================= The session so far ================= --}}
<div class="card mt-6">
    <div class="panel-header">
        <div>
            <p class="panel-title">The session so far</p>
            <p class="mt-0.5 text-xs text-muted">
                {{ $session?->name ?? 'No session open' }}
                · {{ number_format($totals['bills']) }} {{ Str::plural('bill', $totals['bills']) }} raised
                · {{ number_format($totals['unpaid']) }} still unpaid
            </p>
        </div>

        <span class="badge {{ $totals['rate'] >= 75 ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20' : ($totals['rate'] >= 40 ? 'bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20') }}">
            {{ rtrim(rtrim(number_format($totals['rate'], 1), '0'), '.') }}% collected
        </span>
    </div>

    <div class="p-5 sm:p-6">
        <div class="grid gap-6 sm:grid-cols-3">
            <div>
                <p class="eyebrow">Billed</p>
                <p class="mt-1 font-display text-xl font-semibold text-ink">
                    {{ $school->currency }}{{ number_format($totals['billed'], 2) }}
                </p>
            </div>

            <div>
                <p class="eyebrow">Collected</p>
                <p class="mt-1 font-display text-xl font-semibold text-emerald-700 dark:text-emerald-300">
                    {{ $school->currency }}{{ number_format($totals['collected'], 2) }}
                </p>
            </div>

            <div>
                <p class="eyebrow">Outstanding</p>
                <p class="mt-1 font-display text-xl font-semibold {{ $totals['outstanding'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-muted' }}">
                    {{ $school->currency }}{{ number_format($totals['outstanding'], 2) }}
                </p>
            </div>
        </div>

        <div class="mt-5 h-3 w-full overflow-hidden rounded-full bg-surface-3">
            <div class="h-full rounded-full bg-emerald-500 transition-all"
                 style="width: {{ min($totals['rate'], 100) }}%"></div>
        </div>

        @if ($totals['billed'] > 0 && $totals['outstanding'] > 0)
            <p class="mt-3 text-xs text-muted">
                {{ $school->currency }}{{ number_format($totals['outstanding'], 2) }} is still to come in
                across {{ number_format($totals['unpaid']) }} {{ Str::plural('bill', $totals['unpaid']) }}.
                The classes furthest behind are below.
            </p>
        @endif
    </div>
</div>

{{-- ================= Where it is behind ================= --}}
<div class="mt-6 grid gap-6 lg:grid-cols-3">

    {{-- Class by class. The actionable half: a total on its own says the school is owed
         money, and this says who owes it. --}}
    <div class="card lg:col-span-2">
        <div class="panel-header">
            <div>
                <p class="panel-title">Class by class</p>
                <p class="mt-0.5 text-xs text-muted">Furthest behind first</p>
            </div>

            <a href="{{ route('admin.fees-payments.students-hub') }}"
               class="text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-200">Students hub</a>
        </div>

        <div class="divide-y divide-line-soft">
            @forelse ($perClass as $class)
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-ink">{{ $class['name'] }}</span>
                            <span class="block text-xs text-muted">
                                {{ $class['children'] }} {{ Str::plural('child', $class['children']) }}
                                · {{ $school->currency }}{{ number_format($class['collected'], 0) }} of
                                {{ $school->currency }}{{ number_format($class['billed'], 0) }}
                            </span>
                        </span>

                        <span class="shrink-0 text-right">
                            @if ($class['outstanding'] > 0)
                                <span class="block text-sm font-semibold text-rose-600 dark:text-rose-400">
                                    {{ $school->currency }}{{ number_format($class['outstanding'], 2) }}
                                </span>
                                <span class="block text-xs text-muted">still owing</span>
                            @else
                                <span class="block text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                    Fully paid
                                </span>
                                <span class="block text-xs text-muted">nothing outstanding</span>
                            @endif
                        </span>
                    </div>

                    <div class="mt-2.5 h-2 w-full overflow-hidden rounded-full bg-surface-3">
                        <div class="h-full rounded-full {{ $class['rate'] >= 75 ? 'bg-emerald-500' : ($class['rate'] >= 40 ? 'bg-gold-500' : 'bg-rose-500') }}"
                             style="width: {{ min($class['rate'], 100) }}%"></div>
                    </div>
                </div>
            @empty
                <div class="p-5">
                    <x-empty-state
                        icon="receipt"
                        title="Nothing has been billed yet"
                        description="No invoice has been raised against this session, so there is nothing to be behind on. Raise the bills from the fee structures and this fills in class by class." />
                </div>
            @endforelse
        </div>
    </div>

    {{-- How the money arrives. The gateway against a hand-written receipt is the figure
         the office is actually curious about. --}}
    <div class="card">
        <div class="panel-header">
            <div>
                <p class="panel-title">How the money arrives</p>
                <p class="mt-0.5 text-xs text-muted">This session, by method</p>
            </div>
        </div>

        <div class="divide-y divide-line-soft">
            @forelse ($methods as $method)
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-ink">{{ $method['label'] }}</span>
                        <span class="font-mono text-sm font-semibold text-ink">
                            {{ $school->currency }}{{ number_format($method['total'], 2) }}
                        </span>
                    </div>

                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
                        <div class="h-full rounded-full bg-brand-700" style="width: {{ min($method['share'], 100) }}%"></div>
                    </div>

                    <p class="mt-1.5 text-xs text-muted">
                        {{ $method['payments'] }} {{ Str::plural('payment', $method['payments']) }}
                        · {{ rtrim(rtrim(number_format($method['share'], 1), '0'), '.') }}% of the money in
                    </p>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-muted">
                    Nothing has come in against this session's bills yet. Payments appear here as the
                    bursary records them, split by how they were made.
                </p>
            @endforelse
        </div>
    </div>
</div>

{{-- ================= The shape of the year ================= --}}

{{-- Twelve months, not one session: money arrives in the shape of the school year,
     and a September spike is only visible against the quiet months either side. --}}
<div class="card mt-6">
    <div class="panel-header">
        <div>
            <p class="panel-title">Money received, month by month</p>
            <p class="mt-0.5 text-xs text-muted">The last twelve months, across sessions</p>
        </div>
    </div>

        <div class="p-5 sm:p-6">
            @php
                // Scaled against the best month rather than the total, so the shape of the
                // year is what the eye picks up.
                $peak = max((float) $monthly->max('total'), 1);
                $anyMoney = (float) $monthly->sum('total') > 0;
            @endphp

            @if ($anyMoney)
                <div class="flex items-end gap-1.5 sm:gap-2">
                    @foreach ($monthly as $month)
                        <div class="flex min-w-0 flex-1 flex-col items-center">
                            <div class="flex h-32 w-full items-end" title="{{ $month['label'] }}: {{ $school->currency }}{{ number_format($month['total'], 2) }}">
                                <div class="w-full rounded-t {{ $month['total'] > 0 ? 'bg-brand-700' : 'bg-surface-3' }}"
                                     style="height: {{ $month['total'] > 0 ? max(($month['total'] / $peak) * 100, 3) : 2 }}%"></div>
                            </div>

                            <span class="mt-2 truncate text-[10px] text-muted">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-xs text-muted">
                    The best month was {{ $monthly->sortByDesc('total')->first()['label'] }}, at {{ $school->currency }}{{ number_format($peak, 2) }}.
                </p>
            @else
                <x-empty-state
                    icon="chart"
                    title="Nothing has come in yet"
                    description="No payment has been recorded in the last twelve months. Once the bursary starts receiving money, the shape of the year appears here." />
            @endif
        </div>
</div>

{{-- ================= The day book ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="panel-header">
        <div>
            <p class="panel-title">The day book</p>
            <p class="mt-0.5 text-xs text-muted">The last ten receipts, newest first</p>
        </div>

        <a href="{{ route('admin.payments.index') }}"
           class="text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-200">All payments</a>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="w-40 border-b border-r border-line p-3">Receipt</th>
                    <th class="border-b border-r border-line p-3">Student</th>
                    <th class="w-40 border-b border-r border-line p-3">Bill</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Amount</th>
                    <th class="w-40 border-b border-r border-line p-3">Method</th>
                    <th class="w-36 border-b border-line p-3">When</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($recent as $payment)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle font-mono text-xs">
                            {{ $payment->receipt_number }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            @if ($payment->student)
                                <a href="{{ route('admin.students.show', $payment->student) }}"
                                   class="font-medium text-ink hover:underline">{{ $payment->student->full_name }}</a>
                                <span class="mt-0.5 block font-mono text-xs text-muted">
                                    {{ $payment->student->student_number }}
                                </span>
                            @else
                                <span class="text-muted">Unknown student</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle font-mono text-xs">
                            @if ($payment->invoice)
                                <a href="{{ route('admin.invoices.show', $payment->invoice) }}"
                                   class="hover:underline">{{ $payment->invoice->invoice_number }}</a>
                            @else
                                {{-- A receipt with no bill behind it is money held as a credit. --}}
                                <span class="text-muted">Held as credit</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                            {{ $school->currency }}{{ number_format((float) $payment->amount, 2) }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $payment->methodLabel() }}
                        </td>

                        <td class="p-3 align-middle text-xs text-muted">
                            {{ $payment->paid_at?->format('j M Y, H:i') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-6">
                            <x-empty-state
                                icon="cash"
                                title="Nothing has come in yet"
                                description="Payments appear here the moment the bursary records them, newest first — the day book the office reads back to a parent on the telephone." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection

