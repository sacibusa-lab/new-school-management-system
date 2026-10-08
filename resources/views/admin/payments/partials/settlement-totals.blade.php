{{--
    What a collection came to, divided the way the money is: the whole, the school's main
    account, its other accounts, and what the platform kept back.

    Drawn with the section's own stat card rather than a tile of its own, so a row of these
    reads the way the fees and payments screens do — same card, same tinted icon, the same
    three lines of label, figure and note. A page that invented its own tile would be the
    one screen in the section whose numbers looked unlike everybody else's.

    The school's accounts are told apart in two groups rather than one card each: the office
    thinks in "the main account and the others", and a card per account would grow the row
    every time the school opened one. Which of them is the main one is the school's own
    answer — the account it marked primary, the one printed on a letter when nobody says
    otherwise — and it is named in the hint, so the two groups can still be told apart:
    there is no Zenith or Keystone in the code, only the bank the office set up.

    `context` says which level is being summed, and only changes the wording underneath: the
    figures are the same idea at every level, and a card whose hint said "in this term" on
    the session row would be describing the wrong money. `showChart` adds the bar that puts
    the parts back together as the whole — the session is the one level worth drawing,
    because the rows below it are already figures in a list.
--}}
@php
    $money = fn (float $amount): string => $currency.number_format($amount, 2);

    $context = $context ?? 'session';
    $showChart = $showChart ?? false;

    // In the order the school would name them: the primary account first, then the rest as
    // the office added them. Read from the accounts that were actually paid into, because
    // an account that received nothing has no figure to carry.
    $ordered = $accounts
        ->sortBy(fn ($account): array => [$account->is_primary ? 0 : 1, $account->id])
        ->values();

    $main = $ordered->first();
    $others = $ordered->slice(1)->values();

    // The "other" group is taken off the total rather than added up card by card, so the two
    // groups and the whole cannot disagree by a kobo over rounding.
    $mainAmount = $main ? round((float) ($totals['accounts'][$main->id] ?? 0), 2) : 0.0;
    $otherAmount = round(array_sum($totals['accounts']) - $mainAmount, 2);

    $otherNames = $others->pluck('bank_name')->filter()->unique()->values();

    $otherHint = match (true) {
        $otherNames->isEmpty() => 'No other account to pay into',
        $otherNames->count() === 1 => $otherNames->first(),
        default => $otherNames->first().' and '.($otherNames->count() - 1).' more',
    };

    // The tints the section already uses for money that has to go out to a bank, with the
    // platform's own charge on the one warning colour, because it is the only figure on the
    // row that is not the school's.
    $cards = [
        [
            'label' => 'Total collections',
            'value' => $money($totals['collections']),
            'hint' => $context === 'term' ? 'Collected in this term' : 'All collections',
            'icon' => 'receipt',
            'tone' => 'slate',
        ],
        [
            'label' => 'Main Bank',
            'value' => $money($mainAmount),
            'hint' => $main?->bank_name ?? 'No account to pay into',
            'icon' => 'briefcase',
            'tone' => 'brand',
        ],
        [
            'label' => 'Other Bank',
            'value' => $money($otherAmount),
            'hint' => $otherHint,
            'icon' => 'briefcase',
            'tone' => 'emerald',
        ],
        [
            'label' => 'IT maintenance',
            'value' => $money($totals['it']),
            'hint' => $context === 'term' ? 'IT fees in this term' : 'IT / platform fees',
            'icon' => 'scale',
            'tone' => 'rose',
        ],
    ];

    // Only when there is something the splits did not claim. A card that always read zero
    // would suggest a fee nobody had finished setting up.
    if ($totals['unallocated'] > 0) {
        $cards[] = [
            'label' => 'Remaining',
            'value' => $money($totals['unallocated']),
            'hint' => 'Not divided, stays in the main account',
            'icon' => 'cash',
            'tone' => 'gold',
        ];
    }

    $collections = (float) $totals['collections'];

    // The same figures again, as the strips of one bar: each is a part of the collections, so
    // the parts are the whole and nothing on the row is left unaccounted for.
    $parts = [
        ['label' => 'Main Bank', 'amount' => $mainAmount, 'tone' => 'bg-brand-600 dark:bg-brand-500'],
        ['label' => 'Other Bank', 'amount' => $otherAmount, 'tone' => 'bg-emerald-500'],
        ['label' => 'IT maintenance', 'amount' => (float) $totals['it'], 'tone' => 'bg-rose-500'],
        ['label' => 'Remaining', 'amount' => (float) $totals['unallocated'], 'tone' => 'bg-gold-500'],
    ];
@endphp

<div class="space-y-5">
    @if ($showChart && $collections > 0)
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-muted">How the collections divide</p>

            {{-- One strip per part, drawn by width rather than with a charting library: this
                 is four shares of one whole, and a library would be a page of script for a
                 bar the browser already draws. --}}
            <div class="mt-3 flex h-3 w-full overflow-hidden rounded-full bg-surface-3"
                 role="img"
                 aria-label="Collections divided between the main account, the other accounts, IT maintenance and anything the splits did not claim">
                @foreach ($parts as $part)
                    @if ($part['amount'] > 0)
                        <div class="{{ $part['tone'] }}"
                             style="width: {{ round($part['amount'] / $collections * 100, 2) }}%"
                             title="{{ $part['label'] }} — {{ $money($part['amount']) }}"></div>
                    @endif
                @endforeach
            </div>

            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($parts as $part)
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $part['tone'] }}"></span>
                        <dt class="truncate text-xs text-muted">{{ $part['label'] }}</dt>
                        <dd class="ml-auto shrink-0 font-mono text-xs font-semibold text-ink">
                            {{ $money($part['amount']) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <x-stat-card :label="$card['label']"
                         :value="$card['value']"
                         :hint="$card['hint']"
                         :icon="$card['icon']"
                         :tone="$card['tone']" />
        @endforeach
    </div>
</div>
