@php
    /**
     * The payment slips on paper, four to a sheet.
     *
     * DomPDF renders raw HTML with almost no CSS support, so this view is deliberately
     * standalone: no layout, no Tailwind, inline styles only. DejaVu Sans is used because
     * the naira sign is missing from DomPDF's default Helvetica and would print as a box —
     * the same reason the admission letter uses it.
     *
     * Two columns rather than one: the slips are cut up and sent home, and a slip down the
     * middle of an A4 page wastes the other half of it. Each one is kept whole on its page
     * by `page-break-inside`, because two halves of a slip are worth nothing to anybody.
     */
    $currency = $school->currency ?? '₦';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment schedule</title>

    <style>
        @page { margin: 12mm 10mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
            color: #1e293b;
            margin: 0;
        }

        .sheet-head {
            border-bottom: 1pt solid #94a3b8;
            padding-bottom: 2mm;
            margin-bottom: 3mm;
        }

        .sheet-head h1 { font-size: 14pt; margin: 0; }
        .sheet-head p { margin: 1pt 0 0; font-size: 8pt; color: #475569; }

        table.grid { width: 100%; border-collapse: collapse; }
        td.column { width: 50%; vertical-align: top; padding: 1.5mm; }

        .slip {
            border: 0.5pt solid #cbd5e1;
            padding: 3mm;
            page-break-inside: avoid;
        }

        .slip h3 { margin: 0; font-size: 9.5pt; }
        .slip .meta { margin: 1pt 0 0; font-size: 7pt; color: #475569; }
        .slip .standing { font-size: 7pt; color: #475569; text-align: right; }

        .bank {
            border: 0.5pt solid #e2e8f0;
            background: #f8fafc;
            padding: 2mm;
            margin-top: 2mm;
        }

        .bank .label { font-size: 6pt; text-transform: uppercase; color: #64748b; letter-spacing: 0.3pt; }
        .bank .number { font-size: 10pt; font-weight: bold; }
        .bank .name { font-size: 7pt; color: #475569; }
        .bank .none { font-size: 7pt; color: #64748b; }

        table.fees { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 7pt; }
        table.fees td { padding: 1pt 0; border-bottom: 0.25pt solid #e2e8f0; }
        table.fees td.amount { text-align: right; width: 26mm; }
        table.fees tr.arrears td { color: #b91c1c; }
        table.fees tr.discount td { color: #047857; }
        table.fees tr.total td {
            border-top: 0.75pt solid #94a3b8;
            border-bottom: none;
            font-weight: bold;
            font-size: 8.5pt;
            padding-top: 1.5pt;
        }

        .foot { margin-top: 1.5mm; font-size: 6.5pt; color: #64748b; }
    </style>
</head>

<body>
    {{--
        `$school` is the object AppServiceProvider hands to every view — name, address,
        phone and all. It is not passed in here, and a `school` key of our own would be
        overwritten by it anyway, which is what turned this header into a stdClass.
    --}}
    <div class="sheet-head">
        <h1>{{ $school->name }}</h1>
        <p>
            {{ $term }} · {{ $session }}
            @if ($school->address) · {{ $school->address }} @endif
            @if ($school->phone) · {{ $school->phone }} @endif
        </p>
        <p>{{ $label }} — {{ $sheet['slips']->count() }} @if ($sheet['slips']->count() === 1) slip @else slips @endif</p>
    </div>

    @foreach ($sheet['slips']->chunk(2) as $row)
        <table class="grid">
            <tr>
                @foreach ($row as $slip)
                    <td class="column">
                        <div class="slip">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="vertical-align: top;">
                                        <h3>{{ $slip['name'] }}</h3>
                                        <p class="meta">
                                            {{ $slip['number'] }}@if ($slip['class']) · {{ $slip['class'] }}@endif
                                        </p>
                                    </td>
                                    <td class="standing" style="vertical-align: top;">
                                        {{ $standings[$slip['status']] }}
                                    </td>
                                </tr>
                            </table>

                            <div class="bank">
                                <div class="label">Pay into</div>
                                @if ($slip['account'])
                                    <div class="number">{{ $slip['account'] }}</div>
                                    <div class="name">{{ $slip['bank'] }}</div>
                                @else
                                    <div class="none">No account number issued yet.</div>
                                @endif
                            </div>

                            <table class="fees">
                                @foreach ($slip['lines'] as $line)
                                    <tr>
                                        <td>{{ $line['title'] }}</td>
                                        <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                    </tr>
                                @endforeach

                                @foreach ($slip['adjustments'] as $line)
                                    <tr class="{{ $line['amount'] < 0 ? 'discount' : 'arrears' }}">
                                        <td>{{ $line['title'] }}</td>
                                        <td class="amount">
                                            {{ $line['amount'] < 0 ? '-' : '+' }}{{ $currency }}{{ number_format(abs($line['amount']), 2) }}
                                        </td>
                                    </tr>
                                @endforeach

                                @foreach ($slip['arrears'] as $line)
                                    <tr class="arrears">
                                        <td>{{ $line['title'] }}</td>
                                        <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                    </tr>
                                @endforeach

                                @if ($slip['discount'] > 0)
                                    <tr class="discount">
                                        <td>Discount</td>
                                        <td class="amount">-{{ $currency }}{{ number_format($slip['discount'], 2) }}</td>
                                    </tr>
                                @endif

                                <tr class="total">
                                    <td>Still to pay</td>
                                    <td class="amount">{{ $currency }}{{ number_format($slip['due'], 2) }}</td>
                                </tr>
                            </table>

                            <div class="foot">
                                Charged {{ $currency }}{{ number_format($slip['expected'], 2) }}
                                · Paid {{ $currency }}{{ number_format($slip['paid'], 2) }}
                            </div>
                        </div>
                    </td>
                @endforeach

                @if ($row->count() === 1)
                    <td class="column"></td>
                @endif
            </tr>
        </table>
    @endforeach
</body>
</html>
