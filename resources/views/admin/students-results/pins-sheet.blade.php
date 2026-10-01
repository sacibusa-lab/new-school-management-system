@php
    // A standalone sheet, like the merit list: what prints is the PINs and nothing
    // else, because the office prints this to cut up and hand out.
    $issued = $students->filter(fn ($student) => isset($pins[$student->id]));
    $missing = $students->count() - $issued->count();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Result PINs — {{ $class->name }} {{ $term->name }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 print:bg-white">

<div class="mx-auto max-w-3xl p-6 print:max-w-none print:p-0">

    <div class="no-print mb-5 flex flex-wrap items-center gap-3">
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">
            Print {{ $issued->count() }} PIN(s)
        </button>
        <a href="{{ route('admin.students-results.pins', ['class' => $class->id]) }}" class="btn-ghost btn-sm">
            &larr; Back to the PINs
        </a>
    </div>

    <div class="card p-6 print:border-0 print:p-0 print:shadow-none">
        <header class="border-b border-slate-200 pb-4">
            @if ($school->letterhead)
                <img src="{{ asset('storage/' . $school->letterhead) }}"
                     alt="{{ $school->name }}"
                     class="mx-auto block w-full">
            @else
                <h1 class="font-display text-xl font-semibold">{{ $school->name }}</h1>
                @if ($school->address)
                    <p class="text-xs text-slate-500">{{ $school->address }}</p>
                @endif
            @endif

            <p class="mt-4 text-center text-sm font-semibold">
                Result checker PINs — {{ $class->name }}
            </p>
            <p class="text-center text-xs text-slate-500">
                {{ $term->name }} · {{ $session->name }}
            </p>
        </header>

        @if ($issued->isEmpty())
            <p class="py-12 text-center text-sm text-slate-500">
                Nobody in {{ $class->name }} has a PIN yet. Generate them first.
            </p>
        @else
            <table class="mt-5 w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-10 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">#</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Student</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Admission number</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">PIN</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($issued as $student)
                        <tr>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $loop->iteration }}</td>
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $student->full_name }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-500">
                                {{ $student->student_number ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-base font-semibold tracking-widest text-slate-900">
                                {{ $pins[$student->id]->grouped() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p class="mt-4 text-xs text-slate-500">
                One PIN to a student, for {{ $term->name }} only. It is what lets a parent check
                that term's result on the school's website.
                @if ($missing > 0)
                    {{ $missing }} student(s) in this class have no PIN yet and are not on this sheet.
                @endif
            </p>
        @endif
    </div>
</div>

</body>
</html>
