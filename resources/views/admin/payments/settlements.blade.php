@extends('layouts.admin')

@section('title', 'Settlements & Payouts')
@section('subtitle', 'Fees & Payments')

@section('content')
@php
    $money = fn (float $amount): string => $currency.number_format($amount, 2);

    // Which parts of the year are open on arrival. The session and its terms and months are
    // open and the days are not, so the page lands on the calendar rather than on a wall of
    // collapsed rows, and the days — which are what the office works through — stay shut
    // until asked for.
    $initiallyOpen = ['session' => true];

    foreach ($terms as $term) {
        $initiallyOpen['term-'.$term['id']] = true;

        foreach ($term['months'] as $month) {
            $initiallyOpen['month-'.$term['id'].'-'.$month['label']] = true;
        }
    }

    $statusLabels = [
        \App\Services\Fees\SettlementService::STATUS_AWAITING => [
            'label' => 'Awaiting settlement',
            'class' => 'bg-gold-100 text-gold-800 dark:bg-gold-950/50 dark:text-gold-300',
        ],
        \App\Services\Fees\SettlementService::STATUS_READY => [
            'label' => 'Ready for split',
            'class' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
        ],
        \App\Services\Fees\SettlementService::STATUS_DISBURSED => [
            'label' => 'All disbursed',
            'class' => 'bg-surface-3 text-ink-soft',
        ],
    ];
@endphp

{{--
    What the gateway collected, read the way the year is: session, term, month, and the day
    the money came in.

    The day is the leaf because the day is the question. A payout is made against one day's
    collection, not against a month's, so the accounts, the platform's charge and whatever
    was left undivided are shown per day — which turns "how much is ours" into a list of
    transfers somebody can make.

    Each day carries where it has got to: awaiting settlement while the gateway has not
    finished with it, ready for split once every payment of the day is settled, and all
    disbursed once the transfers have been made.
--}}
<p class="max-w-3xl text-sm text-muted">
    Organize collections by session, term, and month. Click to expand.
</p>

@if ($sessions->isNotEmpty())
    {{-- ================= Which session ================= --}}
    <div class="mt-6 flex flex-wrap items-center gap-2">
        @foreach ($sessions as $option)
            {{-- The session in hand is the dark slab: the page is scoped to one year, so which
                 one is not a field to fill in but the first thing to read. --}}
            <a href="{{ route('admin.payments.settlements', ['session' => $option->id]) }}"
               class="inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition-all duration-150 {{ $session?->id === $option->id
                   ? 'border-brand-900 bg-brand-900 text-white dark:border-brand-600 dark:bg-brand-600'
                   : 'border-line bg-surface text-ink-soft hover:border-slate-400 hover:bg-surface-2' }}">
                {{ $option->name }}

                @if ($option->is_current)
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.04em] {{ $session?->id === $option->id ? 'bg-white/20 text-white' : 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200' }}">
                        Current
                    </span>
                @endif
            </a>
        @endforeach
    </div>
@endif

{{-- ================= The session's totals ================= --}}
{{-- The session's figures on a card of their own, the way a summary reads: the panel says which
     year it is, and the cards inside say what the year came to. --}}
<div class="card mt-6 overflow-hidden">
    <div class="panel-header bg-surface-2">
        <p class="panel-title flex items-center gap-2">
            <x-nav-icon name="calendar" class="h-4 w-4 text-muted" />
            {{ $session?->name ?? 'No session' }}
        </p>
    </div>

    <div class="p-5 sm:p-6">
        @include('admin.payments.partials.settlement-totals', [
            'totals' => $totals,
            'accounts' => $settlementAccounts,
            'currency' => $currency,
            'context' => 'session',
            'showChart' => true,
        ])
    </div>
</div>

@if ($totals['collections'] <= 0)
    {{-- A page of zeroes with no explanation looks like a fault. Say which reason it is. --}}
    <div class="mt-4">
        <x-alert tone="info">
            Nothing has been collected in {{ $session?->name ?? 'any session' }} yet, so there is
            nothing to divide. Money appears here as soon as payments are recorded against this
            session's bills.
        </x-alert>
    </div>
@else
    <div class="mt-6" x-data="{ open: @js($initiallyOpen) }">
        {{-- The session is a level of its own even though the page is already scoped to one
             by the pills above: the year, its terms and its months then read as the calendar
             they are, and a term can be shut back down once it has been read. --}}
        <div class="card overflow-hidden">
            {{-- The year sits on a tinted band at the size of a heading rather than a row: it
                 is the biggest thing on the page and is meant to read as the calendar. --}}
            <button type="button"
                    class="panel-header w-full bg-surface-2 text-left transition hover:bg-surface-3"
                    @click="open['session'] = ! open['session']">
                <span class="flex items-center gap-2.5 font-display text-xl font-semibold text-ink">
                    <span class="inline-flex transition" :class="open['session'] ? 'rotate-90' : ''">
                        <x-nav-icon name="chevron-right" class="h-4 w-4" />
                    </span>
                    {{ $session?->name ?? 'This session' }}
                </span>

                <span class="font-mono text-sm font-semibold text-ink">
                    {{ $money($totals['collections']) }}
                </span>
            </button>

            <div x-show="open['session']" x-cloak class="border-t border-line">
                @foreach ($terms as $term)
                    <div class="border-b border-line last:border-b-0">
                        <button type="button"
                                class="flex w-full flex-wrap items-center justify-between gap-4 px-5 py-3.5 text-left transition hover:bg-surface-2"
                                @click="open[@js('term-'.$term['id'])] = ! open[@js('term-'.$term['id'])]">
                            <span class="flex items-center gap-2 font-display text-base font-semibold text-ink">
                                {{-- The rotation is bound on a plain element, not on the icon: an attribute
                                     beginning with a colon on a component is handed to Blade as a PHP
                                     expression rather than passed through to Alpine. --}}
                                <span class="inline-flex transition"
                                      :class="open[@js('term-'.$term['id'])] ? 'rotate-90' : ''">
                                    <x-nav-icon name="chevron-right" class="h-4 w-4" />
                                </span>
                                {{ $term['label'] }}
                            </span>

                            <span class="font-mono text-sm font-semibold text-ink-soft">
                                {{ $money($term['totals']['collections']) }}
                            </span>
                        </button>

                        <div x-show="open[@js('term-'.$term['id'])]" x-cloak class="bg-surface-2">
                            {{-- What the term came to, in the same parts as the session above,
                                 so a term reads without its months being added up. --}}
                            <div class="border-t border-line p-5">
                                @include('admin.payments.partials.settlement-totals', [
                                    'totals' => $term['totals'],
                                    'accounts' => $settlementAccounts,
                                    'currency' => $currency,
                                    'context' => 'term',
                                ])
                            </div>

                            @foreach ($term['months'] as $month)
                                @php $monthKey = $term['id'].'-'.$month['label']; @endphp

                                <div class="border-t border-line">
                                    {{-- The month is a band of its own: red while it has anything
                                         left to pay out, green once every day in it is done. The
                                         colour and the word say the same thing twice on purpose —
                                         the colour is read down the page, the word read once. --}}
                                    <button type="button"
                                            class="my-3 flex w-full items-center gap-2 rounded-xl border px-3 py-2 text-left text-xs font-bold uppercase tracking-[0.1em] transition {{ $month['pending'] > 0
                                                ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300'
                                                : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' }}"
                                            @click="open[@js('month-'.$monthKey)] = ! open[@js('month-'.$monthKey)]">
                                        {{-- The dot is the month at a glance: red while any of
                                             its days is still to be paid out, green when none
                                             is. --}}
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $month['pending'] > 0 ? 'bg-rose-500 ring-2 ring-rose-500/20' : 'bg-emerald-500 ring-2 ring-emerald-500/20' }}"></span>
                                        {{ $month['label'] }}

                                        <span class="ml-auto flex items-center gap-3">
                                            {{-- How much of the month is still to be moved, which
                                                 is the number the office works down to nought. --}}
                                            <span class="rounded-full px-2.5 py-1 text-xs font-medium normal-case tracking-normal {{ $month['pending'] > 0 ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-200' }}">
                                                {{ $month['pending'] > 0 ? $month['pending'].' pending' : 'All disbursed' }}
                                            </span>

                                            <span class="inline-flex transition"
                                                  :class="open[@js('month-'.$monthKey)] ? 'rotate-90' : ''">
                                                <x-nav-icon name="chevron-right" class="h-3.5 w-3.5" />
                                            </span>
                                        </span>
                                    </button>

                                    {{-- Each collection day is its own card rather than a row of a
                                         table: they are separate jobs, and the office works through
                                         them one at a time. --}}
                                    <div x-show="open[@js('month-'.$monthKey)]" x-cloak
                                         class="space-y-3 border-t border-line bg-surface-2 p-5">
                                @foreach ($month['days'] as $day)
                                    @php
                                        $dayKey = 'day-'.$month['label'].'-'.$day['label'];
                                        $daySettled = collect($day['transactions'])->mapWithKeys(
                                            fn (array $transaction): array => [$transaction['id'] => $transaction['settled']],
                                        );
                                        $daySettledBy = collect($day['transactions'])->mapWithKeys(
                                            fn (array $transaction): array => [$transaction['id'] => $transaction['settled_by']],
                                        );
                                    @endphp

                                    {{-- The day holds its own settled state. Ticking one payment then
                                         updates the day's progress without a reload, and without the
                                         term and month above it having to be recounted — settling
                                         moves no money, so nothing above this line changes. --}}
                                    <div class="card overflow-hidden transition hover:shadow-lift"
                                         x-data="{
                                             settled: @js($daySettled),
                                             by: @js($daySettledBy),
                                             disbursed: @js($day['status'] === \App\Services\Fees\SettlementService::STATUS_DISBURSED),
                                             copiedId: null,
                                             busy: false,
                                             failed: false,
                                             failedMessage: 'That did not go through. Check the connection and try again.',

                                             // The clipboard is not always there — an older browser, or a
                                             // page not served over https — and a throw here would
                                             // abort before the feedback ran, leaving a button that
                                             // looks broken. The old selection trick is the fallback.
                                             async copyNumber(id, text) {
                                                 try {
                                                     if (navigator.clipboard && window.isSecureContext) {
                                                         await navigator.clipboard.writeText(text);
                                                     } else {
                                                         const area = document.createElement('textarea');
                                                         area.value = text;
                                                         area.setAttribute('readonly', '');
                                                         area.style.position = 'fixed';
                                                         area.style.opacity = '0';
                                                         document.body.appendChild(area);
                                                         area.select();
                                                         document.execCommand('copy');
                                                         document.body.removeChild(area);
                                                     }
                                                 } catch (error) {
                                                     // Copying is a convenience. Failing it must not
                                                     // take the panel down with it.
                                                 }

                                                 this.copiedId = id;
                                                 setTimeout(() => { if (this.copiedId === id) { this.copiedId = null; } }, 1500);
                                             },

                                             get total() { return Object.keys(this.settled).length },
                                             get done() { return Object.values(this.settled).filter(Boolean).length },
                                             get all() { return this.total > 0 && this.done === this.total },

                                             get status() {
                                                 if (this.disbursed) { return 'disbursed' }

                                                 return this.all ? 'ready' : 'awaiting'
                                             },

                                             async call(url, body) {
                                                 this.busy = true;
                                                 this.failed = false;

                                                 try {
                                                     const response = await fetch(url, {
                                                         method: 'POST',
                                                         headers: {
                                                             'X-CSRF-TOKEN': @js(csrf_token()),
                                                             'Accept': 'application/json',
                                                             'Content-Type': 'application/json',
                                                         },
                                                         body: JSON.stringify(body),
                                                     });

                                                     if (! response.ok) {
                                                         this.failed = true;

                                                         try {
                                                             const body = await response.json();
                                                             if (body && body.message) { this.failedMessage = body.message; }
                                                         } catch (error) {
                                                             // A failure without a message is still a failure.
                                                         }

                                                         return null;
                                                     }

                                                     return await response.json();
                                                 } catch (error) {
                                                     this.failed = true;

                                                     return null;
                                                 } finally {
                                                     this.busy = false;
                                                 }
                                             },

                                             async toggleOne(id, settleUrl, unsettleUrl) {
                                                 const data = await this.call(this.settled[id] ? unsettleUrl : settleUrl, {});

                                                 if (! data) { return; }

                                                 this.settled[id] = data.settled;
                                                 this.by[id] = data.by;
                                             },

                                             async toggleDay(url) {
                                                 const data = await this.call(url, {
                                                     session: @js($session?->id),
                                                     date: @js($day['date']),
                                                 });

                                                 if (! data) { return; }

                                                 Object.keys(this.settled).forEach(id => {
                                                     this.settled[id] = data.settled;
                                                     this.by[id] = data.by;
                                                 });
                                             },

                                             // Paying a day out is what the month's pending count
                                             // counts, and that count lives above this component.
                                             // So the page is fetched again rather than left showing
                                             // a number that has just become wrong.
                                             async toggleDisburse(url) {
                                                 const data = await this.call(url, {
                                                     session: @js($session?->id),
                                                     date: @js($day['date']),
                                                 });

                                                 if (! data) { return; }

                                                 window.location.reload();
                                             },
                                         }">
                                        {{-- The day reads as it does on the transfer form: the two
                                             figures the office writes down, and where the money has
                                             got to. The whole row opens it. --}}
                                        <div class="flex flex-wrap items-center justify-between gap-3 bg-surface-2 px-5 py-4">
                                            <button type="button"
                                                    class="flex flex-1 flex-wrap items-center gap-x-8 gap-y-3 text-left"
                                                    @click="open[@js($dayKey)] = ! open[@js($dayKey)]">
                                                <span>
                                                    <span class="block text-xs font-semibold uppercase tracking-wider text-muted">Collection day</span>
                                                    <span class="mt-0.5 block text-base font-semibold text-ink">{{ $day['label'] }}</span>
                                                </span>

                                                <span>
                                                    <span class="block text-xs font-semibold uppercase tracking-wider text-muted">Lump sum total</span>
                                                    <span class="mt-0.5 block font-mono text-base font-semibold text-ink">{{ $money($day['totals']['collections']) }}</span>
                                                </span>
                                            </button>

                                            <div class="flex flex-wrap items-center gap-3">
                                                {{-- How much of the day the gateway has finished with,
                                                     which is the reason a day is still waiting. --}}
                                                <span class="text-xs text-muted"
                                                      x-text="all ? 'All settled' : `${done} of ${total} settled`">{{ $day['totals']['settled'] === $day['totals']['count'] ? 'All settled' : $day['totals']['settled'].' of '.$day['totals']['count'].' settled' }}</span>

                                                {{-- Written out as well as bound, so the page says where
                                                     the day has got to before any script runs. --}}
                                                <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                                      :class="{
                                                          'bg-gold-100 text-gold-800 dark:bg-gold-950/50 dark:text-gold-300': status === 'awaiting',
                                                          'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300': status === 'ready',
                                                          'bg-surface-3 text-ink-soft': status === 'disbursed',
                                                      }"
                                                      x-text="status === 'disbursed'
                                                          ? 'All disbursed'
                                                          : (status === 'ready' ? 'Ready for split' : 'Awaiting settlement')">{{ $statusLabels[$day['status']]['label'] }}</span>

                                                <span class="inline-flex transition"
                                                      :class="open[@js($dayKey)] ? 'rotate-90' : ''">
                                                    <x-nav-icon name="chevron-right" class="h-4 w-4" />
                                                </span>
                                            </div>
                                        </div>

                                        <p x-show="failed" x-cloak class="px-5 pb-4 text-xs text-rose-600 dark:text-rose-400"
                                           x-text="failedMessage"></p>

                                        <div x-show="open[@js($dayKey)]" x-cloak class="grid gap-4 border-t border-line bg-surface p-5 lg:grid-cols-2">
                                            {{-- What to move, and to where. The bank's own name for the
                                                 account, so a transfer can be made from this page. --}}
                                            <div class="rounded-xl border border-line bg-surface-2 p-5">
                                                <p class="mb-4 flex items-center gap-2 text-sm font-semibold text-ink-soft">
                                                    <x-nav-icon name="briefcase" class="h-4 w-4" />
                                                    Manual transfer instructions
                                                </p>

                                                <div class="mt-4 space-y-4">
                                                    @foreach ($day['totals']['accounts'] as $accountId => $amount)
                                                        @php
                                                            $account = $settlementAccounts->get($accountId);
                                                            $number = $account?->account_number;
                                                        @endphp

                                                        {{-- Each account is a card of its own, and
                                                             highlighted: this is the money somebody
                                                             has to go and move. --}}
                                                        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-800 dark:bg-brand-950/30">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">
                                                                    {{ $account?->bank_name ?? 'Account removed' }}
                                                                </p>

                                                                @if ($number)
                                                                    {{-- The number is what somebody reads out or
                                                                         types into their bank; copying it beats
                                                                         selecting eleven digits by hand. --}}
                                                                    <button type="button" class="btn-ghost btn-sm"
                                                                            title="Copy account number"
                                                                            @click="copyNumber(@js($accountId), @js($number))">
                                                                        <x-nav-icon name="copy" class="h-3.5 w-3.5" />
                                                                        <span x-text="copiedId === @js($accountId) ? 'Copied' : 'Copy'">Copy</span>
                                                                    </button>
                                                                @endif
                                                            </div>

                                                            <p class="mt-1 truncate text-sm font-semibold text-ink">
                                                                {{ $account?->account_name ?? 'An account no longer held' }}
                                                            </p>
                                                            <p class="font-mono text-xs text-muted">{{ $number ?? '—' }}</p>

                                                            <p class="mt-1.5 font-mono text-lg font-bold text-brand-700 dark:text-brand-300">
                                                                {{ $money((float) $amount) }}
                                                            </p>
                                                        </div>
                                                    @endforeach

                                                    @if ($day['totals']['it'] + $day['totals']['unallocated'] > 0)
                                                        {{-- The money nobody has to move: it stays
                                                             where it is, so this card is plain
                                                             against the highlighted transfers. --}}
                                                        <div class="rounded-xl border border-line bg-surface p-4">
                                                            <p class="text-xs font-semibold uppercase tracking-wider text-muted">
                                                                Remainder / IT maintenance
                                                            </p>
                                                            <p class="mt-1 text-sm font-semibold text-ink">
                                                                Keep in the main account
                                                            </p>

                                                            <p class="mt-1.5 font-mono text-lg font-bold text-ink">
                                                                {{ $money($day['totals']['it'] + $day['totals']['unallocated']) }}
                                                            </p>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Where the day's figure came from, so it can be
                                                 checked against the gateway without leaving. --}}
                                            {{-- Capped, because a term's worth of payments down
                                                 one day would otherwise push the payout buttons
                                                 off the page. --}}
                                            <div class="max-h-[420px] overflow-y-auto rounded-xl border border-line bg-surface">
                                                <div class="border-b border-line bg-surface-2 px-4 py-3">
                                                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
                                                        <x-nav-icon name="receipt" class="h-3.5 w-3.5" />
                                                        Transaction breakdown ({{ count($day['transactions']) }})
                                                    </p>
                                                </div>

                                                <table class="w-full border-collapse text-sm">
                                                    <thead>
                                                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                                                            <th class="sticky top-0 border-b border-r border-line bg-surface-3 p-3">Student</th>
                                                            <th class="sticky top-0 w-28 border-b border-r border-line bg-surface-3 p-3 text-right">Amount</th>
                                                            <th class="sticky top-0 w-24 border-b border-r border-line bg-surface-3 p-3 text-right">Time</th>
                                                            <th class="sticky top-0 w-32 border-b border-line bg-surface-3 p-3 text-right">Settled</th>
                                                        </tr>
                                                    </thead>

                                                    <tbody class="divide-y divide-line">
                                                        @foreach ($day['transactions'] as $transaction)
                                                            @php
                                                                $transactionId = $transaction['id'];
                                                                $settleUrl = route('admin.payments.settlements.settle', ['payment' => $transactionId]);
                                                                $unsettleUrl = route('admin.payments.settlements.unsettle', ['payment' => $transactionId]);
                                                                $toggle = "toggleOne({$transactionId}, "
                                                                    .\Illuminate\Support\Js::from($settleUrl).', '
                                                                    .\Illuminate\Support\Js::from($unsettleUrl).')';
                                                            @endphp

                                                            <tr :class="settled[{{ $transactionId }}] ? 'bg-emerald-50/50 dark:bg-emerald-950/20' : ''">
                                                                <td class="border-r border-line p-3 align-middle text-ink-soft">{{ $transaction['student'] }}</td>
                                                                <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                                                                    {{ $money($transaction['amount']) }}
                                                                </td>
                                                                <td class="border-r border-line p-3 text-right align-middle font-mono text-xs text-muted">
                                                                    {{ $transaction['time'] }}
                                                                </td>

                                                                {{-- One payment at a time, because one transfer
                                                                     at a time is how it is done at the bank.
                                                                     Pressing it again undoes it, for a row
                                                                     ticked that had not actually moved. --}}
                                                                <td class="p-3 text-right align-middle">
                                                                    <button type="button" class="btn-secondary btn-sm"
                                                                            x-show="! settled[{{ $transactionId }}]"
                                                                            :disabled="busy"
                                                                            @click="{{ $toggle }}">
                                                                        Settle
                                                                    </button>

                                                                    <button type="button" class="badge-neutral"
                                                                            x-show="settled[{{ $transactionId }}]"
                                                                            :disabled="busy"
                                                                            :title="'Settled by ' + (by[{{ $transactionId }}] ?? 'the office')"
                                                                            @click="{{ $toggle }}">
                                                                        <x-nav-icon name="check" class="h-3 w-3" />
                                                                        Settled
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        {{-- The two things the office does with a day: say the
                                             gateway has finished with every payment of it, and say
                                             the transfers have been made. The second is the last
                                             step of the money's journey, so it is only offered once
                                             the first is done — paying a day out half-collected
                                             would mean working the transfer out again afterwards. --}}
                                        <div x-show="open[@js($dayKey)]" x-cloak
                                             class="space-y-3 border-t border-line bg-surface px-5 py-5">
                                            <p class="text-xs text-muted">
                                                @if ($day['disbursed_at'])
                                                    Paid out {{ $day['disbursed_at']->format('j M Y') }}@if ($day['disbursed_by'])
                                                        by {{ $day['disbursed_by'] }}@endif.
                                                @else
                                                    Make the transfers above, then mark the day paid out.
                                                @endif
                                            </p>

                                            {{-- The last step, and the one thing on this panel that is a
                                                 statement rather than a figure: nothing here can see a
                                                 bank account, so the office says the money moved. Full
                                                 width, because it is the thing the page is for. --}}
                                            {{-- The page's own action, in the pink the rest of the
                                                 money-moving furniture is in: navy here would read
                                                 as one more button beside the secondary ones. --}}
                                            <button type="button"
                                                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[#E91E63] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#d81b60] disabled:pointer-events-none disabled:bg-slate-300 dark:disabled:bg-slate-600"
                                                    x-show="! disbursed"
                                                    :disabled="busy || ! all"
                                                    @click="toggleDisburse(@js(route('admin.payments.settlements.day.disburse')))">
                                                <x-nav-icon name="check" class="h-4 w-4" />
                                                I Have Completed These Manual Transfers
                                            </button>

                                            <button type="button" class="btn-secondary w-full justify-center" x-show="disbursed"
                                                    :disabled="busy"
                                                    @click="toggleDisburse(@js(route('admin.payments.settlements.day.undisburse')))">
                                                Undo the payout
                                            </button>

                                            {{-- Settling is the step before: saying the gateway has
                                                 finished with every payment of the day. Kept beside the
                                                 payout rather than in the day's header so that
                                                 everything the office does with a day sits together. --}}
                                            <div class="flex flex-wrap items-center justify-end gap-2">
                                                <button type="button" class="btn-secondary btn-sm" x-show="! all"
                                                        :disabled="busy"
                                                        @click="toggleDay(@js(route('admin.payments.settlements.day.settle')))">
                                                    <x-nav-icon name="check" class="h-3.5 w-3.5" />
                                                    Settle the day
                                                </button>

                                                <button type="button" class="btn-ghost btn-sm" x-show="all && ! disbursed"
                                                        :disabled="busy"
                                                        @click="toggleDay(@js(route('admin.payments.settlements.day.unsettle')))">
                                                    Undo the day
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
            </div>
        </div>
    </div>
@endif
@endsection