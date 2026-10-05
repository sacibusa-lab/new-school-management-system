@php
    /**
     * The payment slips on paper.
     *
     * The pattern is the one the fee desk has printed for years: the crest at the head of
     * every slip, the school's name under it, the term the slip is for, then a boxed block
     * with the child on the left and the account their money goes into on the right, the
     * charges in a bordered Session / Term / Fee Detail / Amount table, and the figure still
     * owed centred underneath behind a rule. A parent recognises it before they read it.
     *
     * So the head is repeated on every slip rather than printed once at the top of the sheet:
     * a slip is cut off and goes home on its own, and half of a letterhead is no letterhead.
     *
     * This is the one place in the app that cannot use the app's own card: DomPDF renders raw
     * HTML with almost no CSS support and no Tailwind, so `.card` and `<x-brand-mark />` are
     * not available to it and the rules below are written out by hand. Change a token in
     * `app.css` and it will not follow — the same is true of every other printed item.
     *
     * The numbers below are tuned rather than chosen: six slips have to fit on one A4 page,
     * and they only do from these settings downwards. DomPDF lays text out about a sixth
     * taller than a browser does, so a version measured in a browser and trusted on that
     * count is how a six-slip sheet quietly becomes a two-page one. Measured the hard way --
     * hiding one part at a time and counting pages. Seven slips take two pages, six do not.
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
        /* Tighter than the admission letter's margins: the sheet is cut up, and every
           millimetre of paper is a millimetre the slips can use. 3mm is what the fee desk's
           own slips have always printed at. */
        @page { margin: 3mm 6mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #111827;
            margin: 0;
        }

        table.sheet { width: 100%; border-collapse: separate; border-spacing: 1.3mm 1.3mm; }
        td.column { width: 50%; vertical-align: top; }

        /* ============ The slip ============ */
        .slip {
            border: 0.6pt solid #d1d5db;
            border-radius: 1.5mm;
            padding: 2mm 2.5mm;
            text-align: center;
            page-break-inside: avoid;
        }

        /* ============ The head, printed on every slip ============ */
        /* Both of these are the same 9mm, and the crest is capped at the same height where it
           is loaded, so no school's crest can make the slips taller than six to a page. */
        .monogram {
            width: 9mm;
            height: 9mm;
            line-height: 9mm;
            margin: 0 auto;
            border-radius: 50%;
            background: #1e2c66;
            color: #ecc45c;
            font-size: 10pt;
            font-weight: bold;
        }

        .school { margin: 0.8mm 0 0; font-size: 11.5pt; font-weight: bold; }
        .event { margin: 1mm 0 0; font-size: 10pt; font-weight: bold; }
        .when { margin: 0.6mm 0 0; font-size: 7pt; color: #4b5563; }

        /* ============ Who it is for, and where the money goes ============ */
        table.who {
            width: 100%;
            border: 0.6pt solid #d1d5db;
            border-collapse: collapse;
            margin-top: 2mm;
            text-align: left;
        }

        table.who td { vertical-align: middle; padding: 0.9mm; }
        td.avatar { width: 13%; text-align: center; }
        td.facts { width: 31%; }
        td.facts.account-details { width: 56%; }
        td.facts p { margin: 0; font-size: 5.5pt; line-height: 1.5; white-space: nowrap; }
        .k { font-weight: bold; }

        /*
            The child's three lines are short — "Reg No: SAC/2026/001" is 18.6mm — and are
            held on one line each, which is the break that mattered: they were breaking
            because the column was too narrow for them, not because they were too long.

            The bank's own name for the account is the one line that can genuinely outgrow
            the box: "SACIBUSA/" and then a child's full name runs to 45mm and beyond. It
            gets the width to stay whole — 49.3mm at 5.5pt, measured with DomPDF's own font
            metrics — but is allowed to wrap rather than run past the border, because the
            bank can hand back something longer than any width this box can offer.

            The two columns are sized to what each has to hold rather than split down the
            middle: an even split gives each about 38mm, and the account line would break
            every single time.
        */
        td.facts.account-details p { white-space: normal; }

        /* The photograph arrives already cropped to a circle, so it needs neither a border nor
           a radius of its own: the transparency in the PNG is what makes it round. */
        .avatar-photo { width: 10mm; height: 10mm; }

        .avatar-mark {
            width: 10mm;
            height: 10mm;
            line-height: 10mm;
            border-radius: 50%;
            background: #f1f5f9;
            border: 0.6pt solid #cbd5e1;
            color: #475569;
            font-size: 10pt;
            font-weight: bold;
            text-align: center;
        }

        /* ============ What is charged ============ */
        table.fees {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            text-align: left;
        }

        table.fees th,
        table.fees td {
            border: 0.6pt solid #d1d5db;
            padding: 1mm 1.5mm;
            font-size: 6.8pt;
        }

        table.fees th { background: #f3f4f6; font-weight: bold; }
        table.fees td.amount, table.fees th.amount { text-align: right; white-space: nowrap; }

        /* A minus reads green — money coming down — and an earlier year's debt reads red,
           the same way round as every other screen in the app. */
        table.fees tr.arrears td { color: #b91c1c; }
        table.fees tr.taken-off td { color: #047857; }

        .due {
            margin: 1.2mm 0 0;
            font-size: 10.5pt;
            font-weight: bold;
            text-decoration: underline;
        }

        /* The credit line, printed on every slip rather than once at the foot of the sheet. */
        .credit { margin-top: 0.5mm; text-align: center; font-size: 6pt; color: #6b7280; }
    </style>
</head>

<body>
    {{--
        `$school` is the object AppServiceProvider hands to every view — name, address,
        phone and all. It is not passed in here, and a `school` key of our own would be
        overwritten by it anyway, which is what once turned this header into a stdClass.
    --}}
    @foreach ($sheet['slips']->chunk(2) as $row)
        <table class="sheet">
            <tr>
                @foreach ($row as $slip)
                    <td class="column">
                        <div class="slip">
                            {{--
                                The crest, or the same navy-and-gold letters the sidebar shows
                                when no crest has been uploaded. Inlined rather than linked:
                                DomPDF does not fetch images over HTTP, so an `<img>` pointing
                                at the site prints as a broken icon.
                            --}}
                            @if ($logo)
                                <img src="{{ $logo['data'] }}"
                                    width="48"
                                    height="48"
                                    alt="School Logo"
                                    style="object-fit: contain;">
                            @else
                                <div class="monogram">{{ $schoolMonogram }}</div>
                            @endif
                            <p class="school">{{ $school->name }}</p>
                            <p class="event">{{ $heading }}</p>
                            <p class="when">
                                @if ($session && $term)
                                    {{ $session }} - {{ $term }}
                                @elseif ($session)
                                    {{ $session }}
                                @elseif ($term)
                                    {{ $term }}
                                @else
                                    School fees
                                @endif
                            </p>

                            <table class="who">
                                <tr>
                                    {{-- The child's face where there is one, and their initials
                                         where there is not. The photograph was cropped to a
                                         circle before it was handed over: DomPDF draws a
                                         rounded border but will not clip a picture to it. --}}
                                    <td class="avatar">
                                        @if (! empty($slip['photo']))
                                            <img class="avatar-photo" src="{{ $slip['photo'] }}" alt="">
                                        @else
                                            <div class="avatar-mark">{{ $slip['initials'] }}</div>
                                        @endif
                                    </td>

                                    <td class="facts">
                                        <p><span class="k">Name:</span> {{ $slip['name'] }}</p>
                                        <p><span class="k">Reg No:</span> {{ $slip['number'] ?: 'Not issued yet' }}</p>
                                        <p><span class="k">Class:</span> {{ $slip['class'] ?: '—' }}</p>
                                    </td>

                                    {{--
                                        The account the fees go into, which is the part of the
                                        slip a parent actually uses: it is what makes a transfer
                                        land on the right child's bill.
                                    --}}
                                    <td class="facts account-details">
                                        <p><span class="k">Account No:</span> {{ $slip['account'] ?: 'Not issued yet' }}</p>
                                        <p><span class="k">Account Name:</span> {{ $slip['account_name'] ?: '—' }}</p>
                                        <p><span class="k">Bank Name:</span> {{ $slip['bank'] ?: '—' }}</p>
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
                                            <td>{{ $session ?: '—' }}</td>
                                            <td>{{ $term ?: '—' }}</td>
                                            <td>{{ $line['title'] }}</td>
                                            <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach

                                    {{--
                                        What the office added or took off, against the term it
                                        was decided in, so a figure the parent does not remember
                                        agreeing to can be traced to the term it belongs to.
                                    --}}
                                    @foreach ($slip['adjustments'] as $line)
                                        <tr class="{{ $line['amount'] < 0 ? 'taken-off' : 'arrears' }}">
                                            <td>{{ $session ?: '—' }}</td>
                                            <td>{{ $term ?: '—' }}</td>
                                            <td>{{ $line['title'] }}</td>
                                            <td class="amount">
                                                {{ $line['amount'] < 0 ? '-' : '+' }}{{ $currency }}{{ number_format(abs($line['amount']), 2) }}
                                            </td>
                                        </tr>
                                    @endforeach

                                    {{--
                                        An earlier year, still unsettled. Its own session in the
                                        Session column, so nobody has to read the year out of a
                                        description.
                                    --}}
                                    @foreach ($slip['arrears'] as $line)
                                        <tr class="arrears">
                                            <td>{{ $line['session'] ?? '—' }}</td>
                                            <td>—</td>
                                            <td>{{ $line['title'] }}</td>
                                            <td class="amount">{{ $currency }}{{ number_format($line['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach

                                    @if ($slip['discount'] > 0)
                                        <tr class="taken-off">
                                            <td>{{ $session ?: '—' }}</td>
                                            <td>{{ $term ?: '—' }}</td>
                                            <td>Discount</td>
                                            <td class="amount">-{{ $currency }}{{ number_format($slip['discount'], 2) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>

                            <p class="due">Amount Due : {{ $currency }}{{ number_format($slip['due'], 2) }}</p>

                            @if ($credit)
                                <p class="credit">{{ $credit }}</p>
                            @endif
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
