{{--
    One payment slip, on screen.

    Not a component: it is included from one page and handed three things — the slip itself,
    the currency symbol, and the words the three standings are written as. The PDF version is
    a separate file on purpose rather than this one with a flag, because DomPDF supports
    almost no CSS and a slip that has to look right in both would end up looking wrong in
    both.
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

        {{-- The child's initials on the navy tile, the way an admit card shows a candidate.
             Screen, sheet and card all carry the same mark, from the same rule. --}}
        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-900 font-display text-xs font-semibold text-gold-300">
            {{ $slip['initials'] }}
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-[10px] font-semibold uppercase tracking-widest text-muted">Payment slip</p>
            <p class="mt-0.5 truncate text-sm font-semibold text-ink">{{ $slip['name'] }}</p>
            <p class="mt-0.5 font-mono text-xs text-muted">
                {{ $slip['number'] }}@if ($slip['class']) · {{ $slip['class'] }}@endif
            </p>
        </div>

        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill[$slip['status']] }}">
            {{ $standings[$slip['status']] }}
        </span>
    </div>

    {{-- The account their fees go into, which is the part of the slip a parent actually
         uses: it is what makes a transfer land on the right child's bill. --}}
    <div class="mt-3 rounded-lg bg-surface-2 px-3 py-2">
        <p class="text-[11px] font-medium uppercase tracking-wide text-muted">Pay into</p>

        @if ($slip['account'])
            <p class="mt-0.5 text-sm font-semibold text-ink">{{ $slip['account'] }}</p>
            <p class="text-xs text-muted">{{ $slip['bank'] }}</p>
        @else
            <p class="mt-0.5 text-xs text-muted">
                No account number has been issued for this child yet.
            </p>
        @endif
    </div>

    <table class="mt-3 w-full text-xs">
        <tbody class="divide-y divide-line-soft">
            @foreach ($slip['lines'] as $line)
                <tr>
                    <td class="py-1.5 pr-2 text-ink-soft">{{ $line['title'] }}</td>
                    <td class="w-24 py-1.5 text-right font-medium text-ink">
                        {{ $currency.number_format($line['amount'], 2) }}
                    </td>
                </tr>
            @endforeach

            {{-- What the office added or took off, by name. Taken off reads green because it
                 is money coming down, and added reads red for the same reason the arrears do:
                 both are the figure going the wrong way for the family holding the slip. --}}
            @foreach ($slip['adjustments'] as $line)
                <tr>
                    <td class="py-1.5 pr-2 {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $line['title'] }}
                    </td>
                    <td class="w-24 py-1.5 text-right font-medium {{ $line['amount'] < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $line['amount'] < 0 ? '-' : '+' }}{{ $currency.number_format(abs($line['amount']), 2) }}
                    </td>
                </tr>
            @endforeach

            {{-- An earlier session's bill, still unsettled. Marked, because a parent
                 looking at a slip that does not match what they remember paying needs to
                 see which year the money was for. --}}
            @foreach ($slip['arrears'] as $line)
                <tr>
                    <td class="py-1.5 pr-2 text-rose-700 dark:text-rose-300">
                        {{ $line['title'] }}@if (! empty($line['session'])) — {{ $line['session'] }}@endif
                    </td>
                    <td class="w-24 py-1.5 text-right font-medium text-rose-700 dark:text-rose-300">
                        {{ $currency.number_format($line['amount'], 2) }}
                    </td>
                </tr>
            @endforeach

            @if ($slip['discount'] > 0)
                <tr>
                    <td class="py-1.5 pr-2 text-emerald-700 dark:text-emerald-300">Discount</td>
                    <td class="w-24 py-1.5 text-right font-medium text-emerald-700 dark:text-emerald-300">
                        -{{ $currency.number_format($slip['discount'], 2) }}
                    </td>
                </tr>
            @endif
        </tbody>

        <tfoot>
            <tr class="border-t border-line">
                <td class="pt-2 text-xs font-medium text-muted">Still to pay</td>
                <td class="w-24 pt-2 text-right font-display text-base font-semibold text-ink">
                    {{ $currency.number_format($slip['due'], 2) }}
                </td>
            </tr>
        </tfoot>
    </table>

    <p class="mt-2 text-[11px] text-muted">
        Charged {{ $currency.number_format($slip['expected'], 2) }}
        · paid {{ $currency.number_format($slip['paid'], 2) }}
        @if ($slip['level']) · {{ $slip['level'] }} @endif
    </p>
</div>
