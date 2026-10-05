@extends('layouts.admin')

@section('title', 'Payment Schedule')
@section('subtitle', 'Fees & Payments')

@section('content')

@php
    // Two short-hands, as the overview has: a rate of 42.0 should read as 42, and this page
    // says "1 child" and "202 children" more than once.
    $count = fn (int $number, string $word): string => $number.' '.\Illuminate\Support\Str::plural($word, $number);
    $money = fn (float $amount): string => $currency.number_format($amount, 2);

    // The three standings the office knows by name, and the whole sheet above them. `all`
    // is not a standing — it is the absence of a choice between the three — but it is a
    // thing to ask for, so it is a fourth button rather than an absence of one.
    $subsets = ['all' => 'Everyone'] + $statuses;

    // What the filters are, as query parameters, so a download carries the same selection
    // the page is showing rather than opening whatever the office last looked at.
    $carry = array_filter([
        'session' => $filters['session']?->id,
        'term' => $filters['term']?->id,
        'class' => $filters['class'],
        'fee' => $filters['fee'] ?: null,
    ]);
@endphp

{{--
    A slip to a child: what they are charged this term, what has been taken off, what is
    still owed from before, and the account the money goes into.

    The sheet is built from the fees rather than from the bills, which is the same choice
    the overview makes and for the same reason — a child nobody has billed yet still owes
    what they owe. It also has to be printable, so this page is a plain GET form and not a
    fetch: it is a page of money, and it should open on a machine that has not finished
    loading the rest of the site.
--}}
<p class="max-w-3xl text-sm text-muted">
    What each child is charged this term, and what is left to pay on it. Show a class, then
    print the slips or send the list.
</p>

{{-- ================= Which term, whose ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <x-field name="session" label="Academic session" type="select"
                 class="lg:col-span-3"
                 placeholder-option="The session the school is in"
                 :value="$filters['session']?->id"
                 :options="$sessions->pluck('name', 'id')->all()" />

        <x-field name="term" label="Term" type="select"
                 class="lg:col-span-3"
                 placeholder-option="The term the school is in"
                 :value="$filters['term']?->id"
                 :options="$terms->pluck('name', 'id')->all()" />

        <x-field name="class" label="Class" type="select"
                 class="lg:col-span-3"
                 placeholder-option="Every class"
                 :value="$filters['class']"
                 :options="$classOptions" />

        <x-field name="fee" label="Payment type" type="select"
                 class="lg:col-span-3"
                 placeholder-option="Every fee"
                 :value="$filters['fee'] ?: null"
                 :options="$feeOptions" />
    </div>

    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn-primary">Show the slips</button>
        <a href="{{ route('admin.payments.schedule') }}" class="btn-secondary">Clear</a>
    </div>
</form>

{{-- ================= Across the sheet ================= --}}
<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card label="Expected"
                 :value="$money($sheet['totals']['expected'])"
                 :hint="$count($sheet['counts']['all'], 'child').' on this sheet'"
                 icon="cash" tone="brand" />

    <x-stat-card label="Received"
                 :value="$money($sheet['totals']['paid'])"
                 :hint="$count($sheet['counts']['completed'], 'child').' settled'"
                 icon="chart" tone="emerald" />

    <x-stat-card label="Outstanding"
                 :value="$money($sheet['totals']['due'])"
                 :hint="$count($sheet['counts']['pending'], 'child').' yet to pay'"
                 icon="report" tone="rose" />

    <x-stat-card label="Part way"
                 :value="$count($sheet['counts']['partial'], 'child')"
                 hint="Part payment made, the rest still owing"
                 icon="rosette" tone="gold" />
</div>

@if ($sheet['counts']['all'] === 0)
    {{-- A blank grid with no reason given reads as a fault. Say which of the two it is. --}}
    <p class="mt-3 max-w-3xl text-xs text-muted">
        Nobody matches this selection. @if ($filters['session'] === null || $filters['term'] === null)
            Set the session and the term the school is in first —
        @else
            Widen the class or the year group —
        @endif
        a sheet with nobody on it is usually a filter, not an empty school.
    </p>
@endif

{{-- ================= Working on the sheet ================= --}}
{{--
    One Alpine component around the toolbar, the grid and the two panels, because all three
    need the same list of ticked children. Nothing written here by hand may contain a double
    quote — it is one HTML attribute, and a stray one ends it early, taking every method in
    the object with it while the page still looks perfectly normal. Everything from the
    server goes through @js(), which encodes it.
--}}
<div x-data="{
         selected: [],
         ids: @js($sheet['slips']->pluck('id')->values()->all()),
         session: @js($filters['session']?->id),
         term: @js($filters['term']?->id),
         adjust: false,
         pay: false,
         mode: 'full',

         get chosen() { return this.selected.length; },
         get everyone() { return this.ids.length > 0 && this.selected.length === this.ids.length; },
         toggleAll() { this.selected = this.everyone ? [] : this.ids.slice(); },
     }">

<div class="mt-6 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.payments.schedule.pdf', $carry) }}" class="btn-primary btn-sm">
        <x-nav-icon name="download" class="h-3.5 w-3.5" />
        Download the slips
    </a>

    {{-- One button rather than four in a row: it is the same list whichever subset is asked
         for, and what changes is which children are in it. The counts are on the menu
         because that is what decides which one the office wants — "Not paid 41" is a
         worklist, and the label on its own is not. --}}
    <div class="relative" x-data="{ menu: false }">
        <button type="button" class="btn-secondary btn-sm" @click="menu = ! menu">
            <x-nav-icon name="download" class="h-3.5 w-3.5" />
            Export the list
        </button>

        <div x-show="menu" x-cloak
             class="absolute left-0 z-10 mt-1 w-56 overflow-hidden rounded-xl border border-line bg-surface shadow-lift"
             @click.outside="menu = false">
            @foreach ($subsets as $subset => $label)
                <a href="{{ route('admin.payments.schedule.export', $carry + ['subset' => $subset]) }}"
                   class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-left text-sm text-ink-soft transition hover:bg-surface-3"
                   @click="menu = false">
                    {{ $label }}
                    <span class="text-xs text-muted">{{ $sheet['counts'][$subset] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    @if ($sheet['slips']->isNotEmpty())
        <span class="mx-1 hidden h-5 w-px bg-line sm:block"></span>

        <label class="flex items-center gap-2 text-xs text-muted">
            <input type="checkbox"
                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                   :checked="everyone"
                   @change="toggleAll()">
            All {{ $sheet['slips']->count() }}
        </label>

        <span class="text-xs text-muted" x-text="chosen + ' chosen'"></span>

        <button type="button" class="btn-secondary btn-sm"
                :disabled="chosen === 0"
                @click="adjust = true">
            Modify amount
        </button>

        <button type="button" class="btn-secondary btn-sm"
                :disabled="chosen === 0"
                @click="pay = true">
            <x-nav-icon name="cash" class="h-3.5 w-3.5" />
            Mark as paid
        </button>
    @endif
</div>

<p class="mt-3 text-xs text-muted">
    {{ $filters['session']?->name ?? 'No session set' }}@if ($filters['term']) · {{ $filters['term']->name }}@endif
    — the active roll, and what each child has paid against this term's bills.
</p>

{{-- ================= The slips ================= --}}
@if ($sheet['slips']->isNotEmpty())
    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($sheet['slips'] as $slip)
            @include('admin.payments.partials.payment-slip', [
                'slip' => $slip,
                'currency' => $currency,
                'standings' => $statuses,
                // The two columns every printed row is labelled with, so the slip on screen
                // carries the same Session / Term headings the one on paper does.
                'session' => $filters['session']?->name,
                'term' => $filters['term']?->name,
            ])
        @endforeach
    </div>
@endif

@include('admin.payments.partials.schedule-actions')

</div>
@endsection