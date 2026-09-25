@php
    /**
     * DomPDF renders raw HTML with almost no CSS support, so this view is
     * deliberately standalone: no layout, no Tailwind, inline styles only.
     *
     * DejaVu Sans is used because the Naira sign (₦) and the · separator are
     * missing from DomPDF's default Helvetica and would print as boxes.
     */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $letter['title'] }} — {{ $applicant->full_name }}</title>

    <style>
        @page { margin: 22mm 18mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11pt;
            line-height: 1.55;
            color: #1e293b;
        }

        .letterhead { text-align: center; border-bottom: 1.5pt solid #94a3b8; padding-bottom: 10pt; }
        .letterhead h1 { font-size: 17pt; margin: 0; letter-spacing: -0.2pt; }
        .letterhead p { margin: 3pt 0 0; font-size: 9.5pt; color: #475569; }

        .reference { margin-top: 18pt; font-size: 10pt; }
        .reference .title { font-weight: bold; font-size: 11.5pt; }
        .reference p { margin: 2pt 0; }

        .body { margin-top: 18pt; }

        table.facts {
            width: 100%;
            margin-top: 24pt;
            border-top: 0.75pt solid #cbd5e1;
            border-collapse: collapse;
            font-size: 10pt;
        }

        table.facts td { padding: 4pt 0; border-bottom: 0.5pt solid #e2e8f0; }
        table.facts td.label { color: #64748b; width: 45%; }
        table.facts td.value { font-weight: bold; text-align: right; }

        .signature { margin-top: 40pt; }
        .signature .name { font-size: 12pt; }
        .signature .line {
            margin-top: 22pt;
            width: 60mm;
            border-top: 0.75pt solid #64748b;
            padding-top: 4pt;
            font-size: 9.5pt;
            color: #475569;
        }

        .note {
            margin-top: 28pt;
            border-top: 0.75pt dashed #cbd5e1;
            padding-top: 8pt;
            font-size: 8.5pt;
            color: #64748b;
        }
    </style>
</head>
<body>

<div class="letterhead">
    <h1>{{ $letterhead['name'] }}</h1>

    @if ($letterhead['address'])
        <p>{{ $letterhead['address'] }}</p>
    @endif

    @if ($letterhead['phone'] || $letterhead['email'])
        <p>{{ collect([$letterhead['phone'], $letterhead['email']])->filter()->implode(' · ') }}</p>
    @endif
</div>

<div class="reference">
    <p class="title">{{ $letter['title'] }}</p>
    <p>Our ref: {{ $reference }}</p>
    <p>Date: {{ $issuedOn->format('j F, Y') }}</p>
</div>

<div class="body">
    {!! nl2br(e($letter['body'])) !!}
</div>

<table class="facts">
    <tr>
        <td class="label">Registration number</td>
        <td class="value">{{ $reference }}</td>
    </tr>
    <tr>
        <td class="label">Admission number</td>
        <td class="value">{{ $studentNumber ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Class admitted into</td>
        <td class="value">{{ $level ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Session</td>
        <td class="value">{{ $session ?? '—' }}</td>
    </tr>

    @if ($average !== null)
        <tr>
            <td class="label">Entrance average</td>
            <td class="value">{{ number_format((float) $average, 2) }}%</td>
        </tr>
        <tr>
            <td class="label">Cutoff mark</td>
            <td class="value">{{ $cutoff !== null ? number_format((float) $cutoff, 2) . '%' : '—' }}</td>
        </tr>
    @endif

    @if ($position)
        <tr>
            <td class="label">Position on merit list</td>
            <td class="value">{{ $position }}</td>
        </tr>
    @endif
</table>

<div class="signature">
    @if ($letter['signatory'])
        <p class="name">{{ $letter['signatory'] }}</p>
    @endif
    <div class="line">{{ $letter['signatoryTitle'] }}</div>
</div>

<p class="note">{{ $note }}</p>

</body>
</html>
