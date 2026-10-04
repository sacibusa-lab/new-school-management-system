@extends('layouts.admin')

@section('title', 'Payments Overview')
@section('subtitle', 'Fees & Payments')

@section('content')

@php
    // Two short-hands. This table says "1 student" and "202 students" twelve times over,
    // and a rate of 42.0 should read as 42.
    $count = fn (int $number, string $word): string => $number.' '.\Illuminate\Support\Str::plural($word, $number);
    $rate = fn (float $percent): string => rtrim(rtrim(number_format($percent, 1), '0'), '.');
@endphp

{{--
    What should have been collected, against what has, read a year group at a time.

    The figure the office acts on is the gap, and it is per year group: "SS2 is behind" is
    something somebody can do something about, and "the school is at 47%" is not. So the
    cards at the top are the headline and the table underneath is the page.

    The expectation comes from the fee, not from the bills — a year group nobody has
    raised a bill for still shows what it owes. That is the point of a screen above the
    register: the register says what has been billed, and this says what should have been.
--}}
<p class="max-w-3xl text-sm text-muted">
    What each year group should have paid this term, and what has actually come in. The
    expectation is worked out from the fees — what one child in that year group is charged —
    so a year group nobody has billed for still shows what it owes.
</p>

{{-- ================= Which session, which term ================= --}}
{{--
    `items-end` keeps the controls on one line, and it holds only while no field carries a
    hint: a taller cell that is bottom aligned lifts its own label above the rest.
--}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <x-field name="session" label="Academic session" type="select"
                 class="lg:col-span-4"
                 placeholder-option="The session the school is in"
                 :value="$filters['session']"
                 :options="$sessions->pluck('name', 'id')->all()" />

        <x-field name="term" label="Term" type="select"
                 class="lg:col-span-4"
                 placeholder-option="The term the school is in"
                 :value="$filters['term']"
                 :options="$terms->pluck('name', 'id')->all()" />

        <div class="flex items-center gap-2 lg:col-span-4">
            <button type="submit" class="btn-primary">Show</button>
            <a href="{{ route('admin.payments.overview') }}" class="btn-secondary">Clear</a>
        </div>
    </div>
</form>

{{-- ================= The headline ================= --}}
<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card label="Received"
                 :value="$currency.number_format($totals['received'])"
                 :hint="$count($totals['payers'], 'student').' paid'"
                 icon="chart" tone="emerald" />

    <x-stat-card label="Expected"
                 :value="$currency.number_format($totals['expected'])"
                 :hint="$count($totals['students'], 'student').' on the roll'"
                 icon="cash" tone="brand" />

    <x-stat-card label="Debt"
                 :value="$currency.number_format($totals['debt'])"
                 :hint="$count($totals['owing'], 'student').' yet to settle'"
                 icon="report" tone="rose" />

    {{-- The discount column on the bill, which is what approving a scholarship or a
         bursary writes. Nothing else produces one, so a school that has let nobody off
         shows nothing here — which is the truth rather than a missing figure. --}}
    <x-stat-card label="Discount applied"
                 :value="$currency.number_format($totals['discount'])"
                 :hint="$count($totals['discounted'], 'student').' let off'"
                 icon="rosette" tone="gold" />
</div>

@if ($totals['expected'] <= 0)
    {{-- A page of zeroes with no explanation is the worst kind of empty screen: it looks
         like a fault. Say which reason it is, so nobody goes looking for one. --}}
    <p class="mt-3 max-w-3xl text-xs text-muted">
        Nothing is expected of anybody, because no active fee applies to
        {{ $session?->name ?? 'this session' }}@if ($term) · {{ $term->name }}@endif.
        Add one under Fees, or give a year group its own amount on that fee's Class Amounts tab.
    </p>
@endif

{{-- ================= Year group by year group ================= --}}
{{--
    The card carries the Alpine component because the chevron that opens a year group is
    a row of the table inside it. The panel itself is included at the bottom of the card,
    which looks odd for something that covers the screen — it is `position: fixed`, so it
    is not laid out here at all, and keeping it inside the component it belongs to is the
    whole reason it is there.

    Nothing written by hand inside `x-data` may contain a double quote, comments
    included: it is one HTML attribute, and a stray one ends it early — Alpine is then
    handed half an object, every method in it disappears, and the page still looks
    perfectly normal. Values from the server go through @js(), which encodes it.
--}}
<div class="card mt-6 overflow-hidden"
     x-data="{
         url: @js(route('admin.payments.level')),
         exportUrl: @js(route('admin.payments.level.export')),
         sessionId: @js($filters['session']),
         termId: @js($filters['term']),
         sessionName: @js($session?->name),
         termName: @js($term?->name),
         currency: @js($currency),

         open: false,
         loading: false,
         failed: false,
         level: { id: null, name: '' },
         unit: 0,
         children: [],
         subclass: '',
         search: '',

         get arms() {
             return [...new Set(this.children.map(child => child.class).filter(Boolean))].sort();
         },

         get visible() {
             return this.matching('');
         },

         get tally() {
             return {
                 completed: this.children.filter(child => child.status === 'completed').length,
                 partial: this.children.filter(child => child.status === 'partial').length,
                 pending: this.children.filter(child => child.status === 'pending').length,
             };
         },

         matching(which) {
             const needle = this.search.trim().toLowerCase();

             return this.children.filter(child => {
                 if (which !== '' && child.status !== which) { return false; }
                 if (this.subclass !== '' && child.class !== this.subclass) { return false; }
                 if (needle === '') { return true; }

                 return child.name.toLowerCase().includes(needle)
                     || child.number.toLowerCase().includes(needle);
             });
         },

         query(extra) {
             const params = new URLSearchParams(extra);

             if (this.sessionId) { params.set('session', this.sessionId); }
             if (this.termId) { params.set('term', this.termId); }

             return params.toString();
         },

         spreadsheet(which) {
             return this.exportUrl + '?' + this.query({ level: this.level.id, subset: which });
         },

         async show(id, name) {
             this.level = { id: id, name: name };
             this.children = [];
             this.subclass = '';
             this.search = '';
             this.failed = false;
             this.loading = true;
             this.open = true;

             try {
                 const response = await fetch(this.url + '?' + this.query({ level: id }), {
                     headers: { 'Accept': 'application/json' },
                 });

                 if (! response.ok) { throw new Error(response.status); }

                 const payload = await response.json();

                 this.unit = payload.unit;
                 this.children = payload.children;
             } catch (problem) {
                 this.failed = true;
             } finally {
                 this.loading = false;
             }
         },

         close() {
             this.open = false;
         },
     }">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface-2 px-5 py-4">
        <div>
            <p class="font-display text-base font-semibold text-ink">By year group</p>
            <p class="mt-0.5 text-xs text-muted">
                {{ $session?->name ?? 'No session set' }}@if ($term) · {{ $term->name }}@endif.
                A family part-way through is counted as having paid and as still owing, because
                both are true of them.
            </p>
        </div>

        <span class="badge-neutral">
            {{ $count($rows->count(), 'year group') }} · {{ $rate($totals['rate']) }}% collected
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="w-12 border-b border-r border-line p-3">S/N</th>
                    <th class="border-b border-r border-line p-3">Year group</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Flat fee</th>
                    <th class="w-40 border-b border-r border-line p-3 text-right">Expected</th>
                    <th class="w-40 border-b border-r border-line p-3 text-right">Received</th>
                    <th class="w-40 border-b border-r border-line p-3 text-right">Debt</th>
                    <th class="w-52 border-b border-r border-line p-3">Collection progress</th>
                    <th class="w-28 border-b border-r border-line p-3 text-right">Discount</th>
                    <th class="w-12 border-b border-line p-3"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($rows as $row)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle text-muted">{{ $row['index'] }}</td>

                        <td class="border-r border-line p-3 align-middle font-medium text-ink">
                            {{ $row['name'] }}
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                            {{ $currency }}{{ number_format($row['unit']) }}
                        </td>

                        {{-- Each of the three money columns carries the count of children it
                             is about, so the figures can be read without opening anything. --}}
                        <td class="border-r border-line p-3 text-right align-middle">
                            <span class="block font-mono text-xs font-semibold text-ink">
                                {{ $currency }}{{ number_format($row['expected']) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $count($row['students'], 'student') }}
                            </span>
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle">
                            <span class="block font-mono text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ $currency }}{{ number_format($row['received']) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $count($row['payers'], 'student') }} paid
                            </span>
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle">
                            <span class="block font-mono text-xs font-semibold {{ $row['debt'] > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-muted' }}">
                                {{ $currency }}{{ number_format($row['debt']) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $count($row['owing'], 'student') }} owing
                            </span>
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-full min-w-24 overflow-hidden rounded-full bg-surface-3">
                                    <div class="h-full rounded-full {{ $row['rate'] >= 75 ? 'bg-emerald-500' : ($row['rate'] >= 40 ? 'bg-gold-500' : 'bg-rose-500') }}"
                                         style="width: {{ min($row['rate'], 100) }}%"></div>
                                </div>

                                <span class="shrink-0 text-xs font-medium text-muted">
                                    {{ $rate($row['rate']) }}%
                                </span>
                            </div>
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                            @if ($row['discount'] > 0)
                                <span class="text-gold-700 dark:text-gold-300">
                                    −{{ $currency }}{{ number_format($row['discount']) }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- The way into the year group: every child on it, one card each,
                             coloured by how far they have got.

                             `.stop` matters. The panel closes when you click outside it, and
                             that listener is on the document — so without stopping this click
                             here, it carries on up, the panel notices the click was outside
                             itself and shuts, and the row appears not to open at all. --}}
                        <td class="p-3 text-center align-middle">
                            <button type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3 hover:text-ink"
                                    title="Who is in {{ $row['name'] }}"
                                    @click.stop="show({{ $row['id'] }}, @js($row['name']))">
                                <x-nav-icon name="chevron-right" class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="p-5">
                            <x-empty-state
                                icon="academic"
                                title="No year groups yet"
                                description="This page reads the school a year group at a time. Add them under Students & Results → Academic, and each one will appear here against what it should have paid." />
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($rows->isNotEmpty() && $totals['expected'] > 0)
                <tfoot class="bg-surface-2 font-semibold text-ink">
                    <tr>
                        <td colspan="3" class="border-t border-r border-line p-3 text-right text-xs uppercase tracking-wider text-muted">
                            Whole school
                        </td>
                        <td class="border-t border-r border-line p-3 text-right font-mono text-xs">{{ $currency }}{{ number_format($totals['expected']) }}</td>
                        <td class="border-t border-r border-line p-3 text-right font-mono text-xs">{{ $currency }}{{ number_format($totals['received']) }}</td>
                        <td class="border-t border-r border-line p-3 text-right font-mono text-xs">{{ $currency }}{{ number_format($totals['debt']) }}</td>
                        <td class="border-t border-r border-line p-3">
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-full min-w-24 overflow-hidden rounded-full bg-surface-3">
                                    <div class="h-full rounded-full {{ $totals['rate'] >= 75 ? 'bg-emerald-500' : ($totals['rate'] >= 40 ? 'bg-gold-500' : 'bg-rose-500') }}"
                                         style="width: {{ min($totals['rate'], 100) }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs font-medium text-muted">{{ $rate($totals['rate']) }}%</span>
                            </div>
                        </td>
                        <td class="border-t border-r border-line p-3 text-right font-mono text-xs">
                            @if ($totals['discount'] > 0)
                                <span class="text-gold-700 dark:text-gold-300">−{{ $currency }}{{ number_format($totals['discount']) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="border-t border-line p-3"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @include('admin.payments.partials.level-detail')
</div>

@endsection