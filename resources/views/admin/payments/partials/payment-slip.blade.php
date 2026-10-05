{{--
    One payment slip, on screen.

    Laid out as the printed one is — the child and the account their money goes into in a
    boxed block, the charges in a Session / Term / Fee Detail / Amount table, and the figure
    still owed centred underneath — so the office sees the same slip the parent will get, and
    a change to either is obvious as a difference between the two.

    Separate from the PDF rather than shared with a flag, because DomPDF supports almost no
    CSS: a slip that had to come out right in both would come out wrong in both.

    Not a component: it is included from one page and handed the slip itself, the currency
    symbol, the words the three standings are written as, and the session and term the sheet
    has been narrowed to.
--}}
@php
    $pill = [
        'completed' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
        'partial' => 'bg-gold-50 text-gold-700 dark:bg-gold-950/40 dark:text-gold-300',
        'pending' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    ];
    $edge = [
        'completed' => 'border-emerald-200 dark:border-emerald-900',
        'partial' => 'border-gold-200 dark:border-gold-900',
        'pending' => 'border-rose-200 dark:border-rose-900',
    ];
@endphp

<div class="flex flex-col rounded-xl border bg-surface p-4 {{ $edge[$slip['status']] }}">

    <div class="flex items-start gap-2.5">
        {{-- Bound to the page's own selection, which the two buttons above the grid read.
             `:value` rather than `value`, so what goes into the array is the number. --}}
        <input type="checkbox"
               class="mt-0.5 h-4 w-4 shrink-0 rounded border-line text-brand-700 dark:text-brand-300"
               :value="{{ $slip['id'] }}"
               x-model="selected"
               aria-label="Choose {{ $slip['name'] }}">

        <div class="min-w-0 flex-1">
            <p class="text-[10px] font-semibold uppercase tracking-widest text-muted">Payment slip</p>
            <p class="mt-0.5 truncate text-sm font-semibold text-ink">{{ $slip['name'] }}</p>
            <p class="mt-0.5 font-mono text-xs text-muted">{{ $slip['number'] }}</p>
        </div>

        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill[$slip['status']] }}">
            {{ $standings[$slip['status']] }}
        </span>
    </div>

    {{--
        The child on the left and the account their money goes into on the right: the two
        halves of the printed slip's box, in the same order and under the same labels, so a
        parent reading out a query on the phone can be followed line for line.
    --}}
    <div class="mt-3 flex items-start gap-3 rounded-lg border border-line p-3">
        {{-- The same round slot as the printed slip carries, which is where a child's face
             goes. The browser crops the photograph here; on paper the crop is done in PHP,
             because nothing in DomPDF will. --}}
        @if ($slip['photo_path'])
            <img src="{{ asset('storage/'.$slip['photo_path']) }}"
                 alt=""
                 class="h-9 w-9 shrink-0 rounded-full object-cover">
        @else
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xs font-semibold text-ink-soft">
                {{ $slip['initials'] }}
            </span>
        @endif

        <dl class="min-w-0 flex-1 space-y-1 text-xs">
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Name:</dt>
                <dd class="min-w-0 break-words text-ink-soft">{{ $slip['name'] }}</dd>
            </div>
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Reg No:</dt>
                <dd class="min-w-0 break-words font-mono text-ink-soft">{{ $slip['number'] ?: 'Not issued yet' }}</dd>
            </div>
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Class:</dt>
                <dd class="min-w-0 break-words text-ink-soft">{{ $slip['class'] ?: '—' }}</dd>
            </div>
        </dl>

        <dl class="min-w-0 flex-1 space-y-1 text-xs">
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Account No:</dt>
                <dd class="min-w-0 break-words font-mono text-ink-soft">{{ $slip['account'] ?: 'Not issued yet' }}</dd>
            </div>
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Account Name:</dt>
                <dd class="min-w-0 break-words text-ink-soft">{{ $slip['account_name'] ?: '—' }}</dd>
            </div>
            <div class="flex gap-1">
                <dt class="shrink-0 font-medium text-ink">Bank Name:</dt>
                <dd class="min-w-0 break-words text-ink-soft">{{ $slip['bank'] ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    <table class="mt-3 w-full text-xs">
        <thead>
            <tr class="bg-surface-2">
                <th class="px-2 py-1.5 text-left font-medium text-ink">Session</th>
                <th class="px-2 py-1.5 text-left font-medium text-ink">Term</th>
                <th class="px-2 py-1.5 text-left font-medium text-ink">Fee Detail</th>
                <th class="px-2 py-1.5 text-right font-medium text-ink">Amount</th>
            </tr>
        </thead>

        <tbody class="divide-y divide-line-soft">
            @foreach ($slip['lines'] as $line)
                <tr>
                    <td class="px-2 py-1.5 text-ink-soft">{{ $session ?: '—' }}</td>
                    <td class="px-2 py-1.5 text-ink-soft">{{ $term ?: '—' }}</td>
                    <td class="px-2 py-1.5 text-ink">{{ $line['title'] }}</td>
                    <td class="px-2 py-1.5 text-right font-medium text-ink">
                        {{ $currency.number_format($line['amount'], 2) }}
                    </td>
                </tr>
            @endforeach

            {{-- What the office added or took off, by name. Taken off reads green because it
                 is money coming down, and added reads red for the same reason the arrears do:
                 both are the figure going the wrong way for the family holding the slip. --}}
            @foreach ($slip['adjustments'] as $line)
                <tr>
                    <td class="px-2 py-1.5 {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $session ?: '—' }}
                    </td>
                    <td class="px-2 py-1.5 {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $term ?: '—' }}
                    </td>
                    <td class="px-2 py-1.5 {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $line['title'] }}
                    </td>
                    <td class="px-2 py-1.5 text-right font-medium {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $line['amount'] < 0 ? '-' : '+' }}{{ $currency.number_format(abs($line['amount']), 2) }}
                    </td>
                </tr>
            @endforeach

            {{-- An earlier session's bill, still unsettled. Its own session in the Session
                 column, so nobody has to read the year out of a description. --}}
            @foreach ($slip['arrears'] as $line)
                <tr>
                    <td class="px-2 py-1.5 text-rose-700 dark:text-rose-300">{{ $line['session'] ?? '—' }}</td>
                    <td class="px-2 py-1.5 text-rose-700 dark:text-rose-300">—</td>
                    <td class="px-2 py-1.5 text-rose-700 dark:text-rose-300">{{ $line['title'] }}</td>
                    <td class="px-2 py-1.5 text-right font-medium text-rose-700 dark:text-rose-300">
                        {{ $currency.number_format($line['amount'], 2) }}
                    </td>
                </tr>
            @endforeach

            @if ($slip['discount'] > 0)
                <tr>
                    <td class="px-2 py-1.5 text-emerald-700 dark:text-emerald-300">{{ $session ?: '—' }}</td>
                    <td class="px-2 py-1.5 text-emerald-700 dark:text-emerald-300">{{ $term ?: '—' }}</td>
                    <td class="px-2 py-1.5 text-emerald-700 dark:text-emerald-300">Discount</td>
                    <td class="px-2 py-1.5 text-right font-medium text-emerald-700 dark:text-emerald-300">
                        -{{ $currency.number_format($slip['discount'], 2) }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="mt-3 text-center font-display text-base font-semibold text-ink">
        <span class="underline">Amount Due : {{ $currency.number_format($slip['due'], 2) }}</span>
    </p>

    {{-- Not on the paper slip, which is read by a parent: kept here because the office is
         the one settling bills and needs to know what has already come in. --}}
    <p class="mt-1 text-center text-[11px] text-muted">
        Charged {{ $currency.number_format($slip['expected'], 2) }}
        · paid {{ $currency.number_format($slip['paid'], 2) }}
        @if ($slip['level']) · {{ $slip['level'] }} @endif
    </p>
</div>
