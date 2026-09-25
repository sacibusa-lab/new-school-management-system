@extends('layouts.public')

@section('title', 'Check result')

@section('content')
<div class="bg-slate-50 py-12">
    <div class="section max-w-5xl">

        <div class="mx-auto max-w-2xl text-center">
            <span class="eyebrow">Results</span>
            <h1 class="mt-5 font-display text-3xl font-semibold text-slate-900 sm:text-4xl">
                Check your result
            </h1>
            <p class="mt-4 text-slate-600">
                Results appear here as soon as the school publishes them for the term.
            </p>
        </div>

        <form method="GET" class="card-pad mx-auto mt-8 max-w-2xl">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="student_number" label="Admission number" required
                         placeholder="SAC/{{ now()->year }}/001"
                         :value="request('student_number')" />

                <x-field name="surname" label="Surname" required
                         placeholder="Okafor"
                         :value="request('surname')" />
            </div>

            <button type="submit" class="btn-primary mt-5 w-full">Check result</button>
        </form>

        @if ($searched)
            <div class="mt-8">
                @if (! $student)
                    <x-alert tone="danger" title="No match found">
                        We could not find a student with that admission number and surname. It
                        looks like <span class="font-mono">SAC/{{ now()->year }}/001</span> — it is
                        not the same as the registration number (SAC-00001) you applied with.
                    </x-alert>

                @elseif (! $student->results_portal_enabled)
                    <x-alert tone="warning" title="Results portal not enabled">
                        Online result checking has not been enabled for {{ $student->full_name }}.
                        Please contact the school office.
                    </x-alert>

                @elseif ($notPublished)
                    <x-alert tone="info" title="No published result yet">
                        No term result has been published for {{ $student->full_name }}
                        ({{ $student->student_number }}) yet. They appear here as soon as the
                        school releases them.
                    </x-alert>

                @else
                    {{-- ================= Student header ================= --}}
                    <div class="card overflow-hidden">
                        <div class="border-b border-slate-200 bg-slate-50/70 p-5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-900 font-display text-base font-semibold text-gold-300">
                                        {{ $student->initials }}
                                    </span>
                                    <div>
                                        <p class="font-display text-lg font-semibold text-slate-900">{{ $student->full_name }}</p>
                                        <p class="font-mono text-sm text-slate-500">{{ $student->student_number }}</p>
                                    </div>
                                </div>

                                <p class="text-sm text-slate-600">
                                    {{ $student->schoolClass?->name ?? $student->level?->name }}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ================= Each term ================= --}}
                    <div class="mt-6 space-y-8">
                        @foreach ($results as $result)
                            <div class="card overflow-hidden">
                                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                                    <div>
                                        <p class="font-display text-base font-semibold text-slate-900">
                                            {{ $result->term?->name }} — {{ $result->academicSession?->name }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ $result->subjects_count }} subject(s)
                                            · published {{ $result->published_at?->format('j M Y') }}
                                        </p>
                                    </div>

                                    <a href="{{ route('public.result.slip', $result) }}?student_number={{ urlencode($student->student_number) }}"
                                       target="_blank"
                                       class="btn-secondary btn-sm">
                                        Printable report card
                                    </a>
                                </div>

                                {{-- Summary strip --}}
                                <div class="grid divide-y divide-slate-200 border-b border-slate-200 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                                    @foreach ([
                                        ['Average', rtrim(rtrim(number_format((float) $result->average, 2), '0'), '.') . '%'],
                                        ['Position', $result->ordinalPosition() . ($result->class_size ? ' of ' . $result->class_size : '')],
                                        ['Grade', $result->grade ?? '—'],
                                        ['Total', rtrim(rtrim(number_format((float) $result->total_score, 2), '0'), '.')],
                                    ] as [$label, $value])
                                        <div class="p-4 text-center">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
                                            <p class="mt-1.5 font-display text-xl font-semibold text-slate-900">{{ $value }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Subjects --}}
                                <div class="overflow-x-auto">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th class="text-right">C.A.</th>
                                                <th class="text-right">Exam</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-center">Grade</th>
                                                <th class="text-center">Position</th>
                                                <th>Remark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($result->items as $item)
                                                <tr>
                                                    <td class="font-medium text-slate-900">{{ $item->subject?->name }}</td>
                                                    <td class="text-right text-sm">{{ rtrim(rtrim(number_format((float) $item->ca_score, 2), '0'), '.') }}</td>
                                                    <td class="text-right text-sm">{{ rtrim(rtrim(number_format((float) $item->exam_score, 2), '0'), '.') }}</td>
                                                    <td class="text-right font-semibold">{{ rtrim(rtrim(number_format((float) $item->total_score, 2), '0'), '.') }}</td>
                                                    <td class="text-center">
                                                        <span class="badge bg-slate-100 text-slate-700 ring-slate-500/20">{{ $item->grade ?? '—' }}</span>
                                                    </td>
                                                    <td class="text-center text-sm text-slate-500">{{ $item->subject_position ?? '—' }}</td>
                                                    <td class="text-sm text-slate-600">{{ $item->is_absent ? 'Absent' : ($item->remark ?? '—') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @if ($result->teacher_remark || $result->principal_remark)
                                    <div class="grid gap-4 border-t border-slate-200 bg-slate-50/70 p-5 sm:grid-cols-2">
                                        @if ($result->teacher_remark)
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Class teacher</p>
                                                <p class="mt-1 text-sm text-slate-700">{{ $result->teacher_remark }}</p>
                                            </div>
                                        @endif

                                        @if ($result->principal_remark)
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Principal</p>
                                                <p class="mt-1 text-sm text-slate-700">{{ $result->principal_remark }}</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
