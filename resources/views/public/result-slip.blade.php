<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report card · {{ $student->full_name }} · {{ $result->term?->name }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .sheet { box-shadow: none; border: 0; }
        }
    </style>
</head>
<body class="bg-slate-100 py-8">

<div class="mx-auto max-w-4xl px-4">

    <div class="no-print mb-5 flex items-center justify-between">
        <a href="{{ route('public.results') }}?student_number={{ urlencode($student->student_number) }}&surname={{ urlencode($student->last_name) }}"
           class="btn-secondary btn-sm">&larr; Back to results</a>

        <button type="button" onclick="window.print()" class="btn-primary btn-sm">Print report card</button>
    </div>

    <div class="sheet rounded-2xl border border-slate-200 bg-white p-8 shadow-card">

        {{-- ================= School header ================= --}}
        <div class="flex items-start justify-between gap-6 border-b-2 border-brand-900 pb-6">
            <div class="flex items-center gap-4">
                <x-brand-mark size="lg" />
                <div>
                    <p class="font-display text-xl font-semibold text-slate-900">{{ $school->name }}</p>
                    <p class="text-sm text-slate-500">{{ $school->address }}</p>
                    <p class="text-sm text-slate-500">{{ $school->phone }}</p>
                </div>
            </div>

            <div class="text-right">
                <p class="font-display text-lg font-semibold text-brand-900">Terminal Report</p>
                <p class="text-sm text-slate-600">{{ $result->term?->name }}</p>
                <p class="text-sm text-slate-600">{{ $result->academicSession?->name }}</p>
            </div>
        </div>

        {{-- ================= Student block ================= --}}
        <div class="mt-6 grid gap-6 sm:grid-cols-2">
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    ['Student name', $student->full_name],
                    ['Admission number', $student->student_number],
                    ['Registration number', $student->admission_number ?? '—'],
                    ['Class', $result->schoolClass?->name ?? $student->schoolClass?->name ?? '—'],
                ] as [$label, $value])
                    <div class="flex gap-3">
                        <dt class="w-40 shrink-0 text-slate-500">{{ $label }}</dt>
                        <dd class="font-medium text-slate-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    ['Subjects offered', $result->subjects_count],
                    ['Total score', rtrim(rtrim(number_format((float) $result->total_score, 2), '0'), '.')],
                    ['Average', rtrim(rtrim(number_format((float) $result->average, 2), '0'), '.') . '%'],
                    ['Position in class', $result->ordinalPosition() . ($result->class_size ? ' of ' . $result->class_size : '')],
                ] as [$label, $value])
                    <div class="flex gap-3">
                        <dt class="w-40 shrink-0 text-slate-500">{{ $label }}</dt>
                        <dd class="font-medium text-slate-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- ================= Subjects ================= --}}
        <table class="mt-8 w-full border-collapse text-sm">
            <thead>
                <tr class="bg-brand-900 text-white">
                    <th class="border border-brand-800 px-3 py-2.5 text-left font-semibold">Subject</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-right font-semibold">C.A.</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-right font-semibold">Exam</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-right font-semibold">Total</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-center font-semibold">Grade</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-center font-semibold">Pos.</th>
                    <th class="border border-brand-800 px-3 py-2.5 text-left font-semibold">Remark</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($result->items as $item)
                    <tr class="even:bg-slate-50">
                        <td class="border border-slate-200 px-3 py-2.5 font-medium text-slate-900">{{ $item->subject?->name }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-right">{{ rtrim(rtrim(number_format((float) $item->ca_score, 2), '0'), '.') }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-right">{{ rtrim(rtrim(number_format((float) $item->exam_score, 2), '0'), '.') }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-right font-semibold">{{ rtrim(rtrim(number_format((float) $item->total_score, 2), '0'), '.') }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-center">{{ $item->grade ?? '—' }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-center text-slate-500">{{ $item->subject_position ?? '—' }}</td>
                        <td class="border border-slate-200 px-3 py-2.5 text-slate-600">{{ $item->is_absent ? 'Absent' : ($item->remark ?? '') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ================= Remarks ================= --}}
        <div class="mt-8 grid gap-6 sm:grid-cols-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Class teacher's remark</p>
                <p class="mt-2 min-h-12 border-b border-dotted border-slate-300 pb-2 text-sm text-slate-800">
                    {{ $result->teacher_remark ?? '' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Principal's remark</p>
                <p class="mt-2 min-h-12 border-b border-dotted border-slate-300 pb-2 text-sm text-slate-800">
                    {{ $result->principal_remark ?? '' }}
                </p>
            </div>
        </div>

        {{-- ================= Footer ================= --}}
        <div class="mt-10 flex items-end justify-between gap-6 border-t border-slate-200 pt-6">
            <div class="text-xs text-slate-500">
                <p>Next term begins: <span class="font-medium text-slate-700">—</span></p>
                <p class="mt-1">Published {{ $result->published_at?->format('j F Y') }}</p>
            </div>

            <div class="text-center">
                @if ($signature)
                    <img src="{{ asset('storage/' . $signature) }}" alt="" class="mx-auto h-12 w-auto">
                @endif

                <div class="w-48 border-b border-slate-400"></div>
                <p class="mt-1.5 text-xs text-slate-500">Principal's signature</p>
            </div>
        </div>

        <p class="mt-6 text-center text-[11px] text-slate-400">
            This report card is computer-generated. Verify authenticity using the admission number above.
        </p>
    </div>
</div>

</body>
</html>
