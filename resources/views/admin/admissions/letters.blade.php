<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admission letters — {{ $exam->title }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            /* One letter to a sheet, and the next one starts on a fresh page. */
            .letter { break-after: page; page-break-after: always; }
            .letter:last-child { break-after: auto; page-break-after: auto; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 print:bg-white">

<div class="mx-auto max-w-3xl p-6 print:max-w-none print:p-0">

    <div class="no-print mb-5 flex flex-wrap items-center gap-3">
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">
            Print all {{ count($letters) }} letter(s)
        </button>
        <a href="{{ route('admin.admissions.merit', $exam) }}" class="btn-secondary btn-sm">Merit list</a>
        <a href="{{ route('admin.admissions.index', ['exam' => $exam->id]) }}" class="btn-ghost btn-sm">
            &larr; Cutoff &amp; decisions
        </a>
        <p class="text-xs text-slate-500">One letter to a sheet.</p>
    </div>

    @if ($letters === [])
        <div class="card-pad text-center">
            <p class="text-sm font-medium text-slate-900">Nobody has been admitted on this examination yet</p>
            <p class="mt-1 text-sm text-slate-500">
                Apply the cutoff first, then come back and the letters will be here.
            </p>
        </div>
    @endif

    @foreach ($letters as $data)
        <article class="letter card mb-6 p-8 print:mb-0 print:rounded-none print:border-0 print:p-0 print:shadow-none">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h1 class="font-display text-lg font-semibold">{{ $data['letterhead']['name'] }}</h1>
                    @if ($data['letterhead']['address'])
                        <p class="text-xs text-slate-500">{{ $data['letterhead']['address'] }}</p>
                    @endif
                    @if ($data['letterhead']['phone'])
                        <p class="text-xs text-slate-500">{{ $data['letterhead']['phone'] }}</p>
                    @endif
                </div>

                <div class="text-right text-xs text-slate-500">
                    <p>{{ $data['issuedOn']->format('j F Y') }}</p>
                    <p class="font-mono">{{ $data['reference'] }}</p>
                </div>
            </header>

            <h2 class="mt-6 font-display text-base font-semibold">{{ $data['letter']['title'] }}</h2>

            <div class="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-800">{{ $data['letter']['body'] }}</div>

            <dl class="mt-6 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">Admission number</dt>
                    <dd class="font-mono font-semibold">{{ $data['studentNumber'] ?? 'To be issued on transfer' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">Class</dt>
                    <dd class="font-semibold">{{ $data['level'] ?? '—' }}</dd>
                </div>
            </dl>

            @if ($data['note'])
                <p class="mt-6 text-xs text-slate-500">{{ $data['note'] }}</p>
            @endif

            <div class="mt-10">
                @if ($data['signature'])
                    <img src="{{ asset('storage/' . $data['signature']) }}" alt="" class="mb-1 h-12 w-auto">
                @endif

                <p class="border-t border-slate-300 pt-2 text-sm font-semibold">
                    {{ $data['letter']['signatory'] ?: '_______________________' }}
                </p>
                <p class="text-xs text-slate-500">{{ $data['letter']['signatoryTitle'] }}</p>
            </div>
        </article>
    @endforeach
</div>

</body>
</html>
