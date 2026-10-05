@php
    /**
     * The payment slips on paper.
     *
     * Deliberately the same card as an admit card: the school's crest on the right behind a
     * hairline, the child on the left in a navy tile with gold initials, the eyebrow above
     * the name, and the details as small uppercase labels over their values. One school, one
     * set of brand items — a slip that looked like a different school's paperwork would be
     * the odd one out in the same envelope.
     *
     * The colours are the app's own tokens, copied rather than imported: DomPDF renders raw
     * HTML with almost no CSS support and no Tailwind, so this view cannot use the `.card`
     * class or `<x-brand-mark />` that the admit card is built from. Slate-900 for ink,
     * slate-400 for the labels, brand-900 and gold-300 for the monogram — the same values,
     * written out. Change one, look at the other.
     *
     * DejaVu Sans because the naira sign is missing from DomPDF's default Helvetica and
     * would print as a box — the same reason the admission letter uses it.
     *
     * `page-break-inside` is what actually matters: half a slip is worth nothing to anybody.
     */
    $currency = $school->currency ?? '₦';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment slips</title>

    <style>
        @page { margin: 9mm 8mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #0f172a;
            margin: 0;
        }

        /* ============ The sheet's own heading, as the admit cards have ============ */
        .sheet-head { text-align: center; margin-bottom: 3mm; }
        .sheet-head h1 { margin: 0; font-size: 13pt; font-weight: bold; }
        .sheet-head .event { margin: 0.6mm 0 0; font-size: 9pt; font-weight: bold; }
        .sheet-head .meta { margin: 0.6mm 0 0; font-size: 6.6pt; color: #64748b; }

        table.sheet { width: 100%; border-collapse: separate; border-spacing: 2.5mm 2.5mm; }
        td.column { width: 50%; vertical-align: top; }

        /* ============ The card ============ */
        .slip {
            border: 0.5pt solid #e2e8f0;
            border-radius: 2mm;
            padding: 3mm;
            page-break-inside: avoid;
        }

        table.top { width: 100%; border-collapse: collapse; }

        /* The child, left: navy tile with their initials in gold — the same treatment a
           student photograph gets on an admit card when there is no photograph. */
        td.tile { width: 14mm; vertical-align: top; }
        .initials {
            width: 13mm;
            height: 15mm;
            line-height: 15mm;
            border-radius: 2.5mm;
            background: #1e2c66;
            color: #ecc45c;
            font-size: 13pt;
            font-weight: bold;
            text-align: center;
        }

        td.who { vertical-align: top; padding-left: 3mm; }
        .eyebrow {
            margin: 0;
            font-size: 5.8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6pt;
            color: #94a3b8;
        }
        .name { margin: 0.6mm 0 0; font-size: 10.5pt; font-weight: bold; }
        .number { margin: 0; font-family: 'DejaVu Sans Mono', monospace; font-size: 6.2pt; color: #64748b; }

        /* Label over value, two pairs to a row — the admit card's form, not a sentence. */
        table.facts { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.facts td { vertical-align: top; padding: 0 2mm 1.4mm 0; width: 50%; }
        .k {
            font-size: 5.6pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
            color: #94a3b8;
        }
        .v { font-size: 6.8pt; color: #475569; }

        /* The school's crest, right, behind a hairline — `<x-brand-mark size="xl" />`. */
        td.mark { width: 17mm; vertical-align: middle; text-align: center; border-left: 0.5pt solid #e2e8f0; }
        .monogram {
            width: 13mm;
            height: 13mm;
            line-height: 13mm;
            border-radius: 2.5mm;
            background: #1e2c66;
            color: #ecc45c;
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
        }

        /* ============ What is charged ============ */
        table.fees { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.fees th {
            padding: 0 1.5mm 1.2mm 0;
            border-bottom: 0.5pt solid #e2e8f0;
            font-size: 5.6pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
            color: #94a3b8;
            text-align: left;
        }
        table.fees td {
            padding: 1.3mm 1.5mm 1.3mm 0;
            border-bottom: 0.25pt solid #f1f5f9;
            font-size: 6.8pt;
            color: #475569;
        }
        table.fees td.detail { color: #0f172a; }
        table.fees td.amount, table.fees th.amount { text-align: right; padding-right: 0; }
        table.fees tr.arrears td { color: #b91c1c; }
        table.fees tr.taken-off td { color: #047857; }

        .due {
            margin: 3mm 0 0;
            padding-top: 2mm;
            border-top: 0.5pt solid #e2e8f0;
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
        }

        .sheet-foot { margin-top: 3.5mm; text-align: center; font-size: 6.6pt; color: #64748b; }
    </style>
</head>

<body>
    {{--
        `$school` is the object AppServiceProvider hands to every view — name, address,
        phone and all. It is not passed in here, and a `school` key of our own would be
        overwritten by it anyway, which is what once turned this header into a stdClass.
    --}}
    <div class="sheet-head">
        <h1>{{ $school->name }}</h1>
        <p class="event">{{ $heading }}</p>
        <p class="meta">
            {{ $session }} · {{ $term }} · {{ $label }} ·
            {{ $sheet['slips']->count() }} @if ($sheet['slips']->count() === 1) slip @else slips @endif
        </p>
    </div>

    @foreach ($sheet['slips']->chunk(2) as $row)
        <table class="sheet">
            <tr>
                @foreach ($row as $slip)
                    <td class="column">
                        <div class="slip">
                            <table class="top">
                                <tr>
                                    <td class="tile">
                                        <div class="initials">{{ $slip['initials'] }}</div>
                                    </td>

                                    <td class="who">
                                        <p class="eyebrow">Payment slip</p>
                                        <p class="name">{{ $slip['name'] }}</p>
                                        <p class="number">{{ $slip['number'] }}</p>

                                        <table class="facts">
                                            <tr>
                                                <td>
                                                    <div class="k">Class</div>
                                                    <div class="v">{{ $slip['class'] ?: '—' }}</div>
                                                </td>
                                                <td>
                                                    <div class="k">Session</div>
                                                    <div class="v">{{ $session ?: '—' }}</div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <div class="k">Term</div>
                                                    <div class="v">{{ $term ?: '—' }}</div>
                                                </td>
                                                <td>
                                                    <div class="k">Account No</div>
                                                    <div class="v">{{ $slip['account'] ?: 'Not issued yet' }}</div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <div class="k">Bank</div>
                                                    <div class="v">{{ $slip['bank'] ?: '—' }}</div>
                                                </td>
                                                <td>
                                                    <div class="k">Account Name</div>
                                                    <div class="v">{{ $slip['account_name'] ?: '—' }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>

                                    <td class="mark">
                                        @if ($logo)
                                            <img src="{{ $logo['data'] }}"
                                                 @if ($logo['width']) width="{{ $logo['width'] }}" @endif
                                                 @if ($logo['height']) height="{{ $logo['height'] }}" @endif
                                                 alt="">
                                        @else
                                            {{-- `<x-brand-mark />` falls back to the school's
                                                 monogram when no crest has been uploaded, and
                                                 so does this: navy tile, gold letters. --}}
                                            <div class="monogram">{{ $schoolMonogram }}</div>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <table class="fees">
                                <thead>
                                    <tr>
                                        <th>Session</th>
                                        <th>Term</th>
                                        <th>Fee Detail</th>
                                        <th class="amount">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($slip['lines'] as $line)
                                        <tr>
                                            <td>{{ $session }}</td>
                                            <td>{{ $term }}</td>
                                            <td class="detail">{{ $line['title'] }}</td>
                                            <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach

                                    {{-- What the office added or took off, against the term it
                                         was decided in. A minus reads green: money coming down. --}}
                                    @foreach ($slip['adjustments'] as $line)
                                        <tr class="{{ $line['amount'] < 0 ? 'taken-off' : 'arrears' }}">
                                            <td>{{ $session }}</td>
                                            <td>{{ $term }}</td>
                                            <td class="detail">{{ $line['title'] }}</td>
                                            <td class="amount">
                                                {{ $line['amount'] < 0 ? '-' : '+' }}{{ $currency }}{{ number_format(abs($line['amount']), 2) }}
                                            </td>
                                        </tr>
                                    @endforeach

                                    {{-- An earlier year, still unsettled. Its own session in the
                                         Session column, so nobody has to read the year out of a
                                         description. --}}
                                    @foreach ($slip['arrears'] as $line)
                                        <tr class="arrears">
                                            <td>{{ $line['session'] ?? '' }}</td>
                                            <td>—</td>
                                            <td class="detail">{{ $line['title'] }}</td>
                                            <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach

                                    @if ($slip['discount'] > 0)
                                        <tr class="taken-off">
                                            <td>{{ $session }}</td>
                                            <td>{{ $term }}</td>
                                            <td class="detail">Discount</td>
                                            <td class="amount">-{{ $currency }}{{ number_format($slip['discount'], 2) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>

                            <p class="due">Amount Due : {{ $currency }}{{ number_format($slip['due'], 2) }}</p>
                        </div>
                    </td>
                @endforeach

                @if ($row->count() === 1)
                    <td class="column"></td>
                @endif
            </tr>
        </table>
    @endforeach

    <p class="sheet-foot">
        Pay into the account on the slip. Keep it as the record of what has been charged.
    </p>
</body>
</html>
