@extends('layouts.admin')

@section('title', 'Check Student Result')
@section('subtitle', 'Students & Results')

@section('content')

{{-- ================= Lookup ================= --}}
<form method="GET" class="card-pad">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-field name="academic_session_id" label="Academic year" type="select" required
                 :placeholder-option="false"
                 :value="$session?->id"
                 :options="$sessions->pluck('name', 'id')->all()" />

        <x-field name="term_id" label="Term" type="select" required
                 :placeholder-option="false"
                 :value="$term?->id"
                 :options="$terms->pluck('name', 'id')->all()" />

        <x-field name="admission_number" label="Admission number" required
                 placeholder="SAC/{{ now()->year }}/001"
                 :value="request('admission_number')" />
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary btn-sm">Check result</button>

        @if ($searched)
            <a href="{{ route('admin.students-results.check-result') }}" class="btn-ghost btn-sm">Clear</a>
        @endif

        <p class="ml-auto text-xs text-muted">
            The registration number (SAC-00001) finds the same student. Unpublished results are
            shown here and labelled — this page is what the public checker cannot show.
        </p>
    </div>
</form>

@if (! $searched)
    {{-- ================= Before a search ================= --}}
    <div class="mt-6">
        <x-empty-state
            icon="search"
            title="Type an admission number to begin"
            description="An admission number, the year and the term — that is all this page needs. It reads the result whether or not the school has published it, so it can answer the call about a result a parent says is missing." />
    </div>
@elseif (! $student)
    {{-- ================= Nobody by that number ================= --}}
    <div class="mt-6">
        <x-alert tone="danger" title="No student with that number">
            <span class="font-mono">{{ $number }}</span> is not an admission number or a
            registration number on this system. They are two different numbers:
            <span class="font-mono">SAC/{{ now()->year }}/001</span> is issued when a child is
            admitted, while <span class="font-mono">SAC-00001</span> is the one they applied
            with. Both are accepted here.
        </x-alert>
    </div>
@else
    {{-- ================= The student ================= --}}
    <div class="card mt-6 overflow-hidden">
        <div class="bg-surface-2 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-900 font-display text-base font-semibold text-gold-300">
                        {{ $student->initials }}
                    </span>
                    <div>
                        <p class="font-display text-lg font-semibold text-ink">{{ $student->full_name }}</p>
                        <p class="font-mono text-sm text-muted">{{ $student->student_number }}</p>
                    </div>
                </div>

                <div class="sm:text-right">
                    <p class="text-sm text-ink-soft">
                        {{ $student->schoolClass?->name ?? $student->level?->name ?? 'No class set' }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted">
                        Admitted {{ $student->admitted_at?->format('j M Y') ?? '—' }}
                        @if ($student->academicSession)
                            · {{ $student->academicSession->name }}
                        @endif
                        @if ($student->admission_number)
                            · registered as {{ $student->admission_number }}
                        @endif
                    </p>
                </div>
            </div>
        </div>

        @if (! $student->results_portal_enabled)
            <div class="border-t border-line p-5">
                <x-alert tone="warning" title="Online checking is switched off">
                    This student's results portal is disabled, so a parent cannot see this result
                    on the public checker however published it is.
                </x-alert>
            </div>
        @endif

        @if (! $result)
            {{-- ================= Nothing recorded for this year and term ================= --}}
            <div class="border-t border-line p-5">
                <x-alert tone="info" title="No result on file for {{ $term?->name }} {{ $session?->name }}">
                    Results are computed one class at a time, so the usual reasons are that
                    {{ $student->schoolClass?->name ?? 'the class' }} has no result computed for that
                    term yet, that the student had moved to another class by then, or that the wrong
                    year or term is selected above.
                </x-alert>
            </div>
        @else
            {{-- ================= The result ================= --}}
            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-line px-5 py-4">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="font-display text-base font-semibold text-ink">
                        {{ $result->term?->name }} — {{ $result->academicSession?->name }}
                    </p>

                    <x-status-pill :status="$result->status" />
                </div>

                @if ($published)
                    <a href="{{ route('public.result.slip', $result) }}?student_number={{ urlencode($student->student_number) }}&surname={{ urlencode($student->last_name) }}"
                       target="_blank"
                       class="btn-secondary btn-sm">
                        Printable report card
                    </a>
                @endif
            </div>

            @if (! $published)
                <div class="border-t border-line p-5">
                    <x-alert tone="warning" title="Not published to parents">
                        The result is on file, but the school has not released
                        {{ $term?->name }} {{ $session?->name }} for
                        {{ $result->schoolClass?->name ?? 'this class' }} yet, so nothing appears on
                        the public checker. That is the answer to give the caller — a term is released
                        from the results page when the office is ready.
                    </x-alert>
                </div>
            @endif

            {{-- Summary --}}
            <div class="grid divide-y divide-line border-t border-line sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                @foreach ([
                    ['Average', rtrim(rtrim(number_format((float) $result->average, 2), '0'), '.') . '%'],
                    ['Position', $result->ordinalPosition() . ($result->class_size ? ' of ' . $result->class_size : '')],
                    ['Grade', $result->grade ?? '—'],
                    ['Total', rtrim(rtrim(number_format((float) $result->total_score, 2), '0'), '.')],
                ] as [$label, $value])
                    <div class="p-4 text-center">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $label }}</p>
                        <p class="mt-1.5 font-display text-xl font-semibold text-ink">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Subjects --}}
            @if ($result->items->isEmpty())
                <div class="border-t border-line p-5">
                    <x-alert tone="info" title="No subject marks yet">
                        The summary above exists but no subject has been marked, which usually means
                        the result was computed before the last scores were entered.
                    </x-alert>
                </div>
            @else
                <div class="overflow-x-auto border-t border-line">
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
                                    <td class="font-medium text-ink">{{ $item->subject?->name }}</td>
                                    <td class="text-right text-sm">{{ rtrim(rtrim(number_format((float) $item->ca_score, 2), '0'), '.') }}</td>
                                    <td class="text-right text-sm">{{ rtrim(rtrim(number_format((float) $item->exam_score, 2), '0'), '.') }}</td>
                                    <td class="text-right font-semibold">{{ rtrim(rtrim(number_format((float) $item->total_score, 2), '0'), '.') }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20">{{ $item->grade ?? '—' }}</span>
                                    </td>
                                    <td class="text-center text-sm text-muted">{{ $item->subject_position ?? '—' }}</td>
                                    <td class="text-sm text-ink-soft">{{ $item->is_absent ? 'Absent' : ($item->remark ?? '—') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($result->teacher_remark || $result->principal_remark)
                <div class="grid gap-4 border-t border-line bg-surface-2 p-5 sm:grid-cols-2">
                    @if ($result->teacher_remark)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted">Class teacher</p>
                            <p class="mt-1 text-sm text-ink-soft">{{ $result->teacher_remark }}</p>
                        </div>
                    @endif

                    @if ($result->principal_remark)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted">Principal</p>
                            <p class="mt-1 text-sm text-ink-soft">{{ $result->principal_remark }}</p>
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endif

@endsection
