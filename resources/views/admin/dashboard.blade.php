@extends('layouts.admin')

@section('title', 'Dashboard')
@section('subtitle', $session ? 'Academic session ' . $session->name : 'No active session')

@section('actions')
    <a href="{{ route('admin.applicants.index') }}" class="btn-secondary btn-sm">All applicants</a>
    <a href="{{ route('admin.imports.index') }}" class="btn-primary btn-sm">
        Upload a scoresheet
    </a>
@endsection

@section('content')

{{-- ================================================================
     1. ADMISSIONS
     ================================================================ --}}
<x-panel-section number="1" title="Admissions"
                 :divider="false"
                 :meta="$session?->name"
                 description="Applications, entrance examinations and the cutoff decisions that feed students into the rest of the school.">

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card
            label="Registered applicants"
            :value="number_format($admissions['total'])"
            :hint="$admissions['today'] . ' registered today'"
            icon="clipboard"
            tone="brand"
            :href="route('admin.applicants.index')" />

        <x-stat-card
            label="Awaiting a decision"
            :value="number_format($admissions['awaiting_decision'])"
            hint="Marked, waiting for the cutoff"
            icon="scale"
            tone="gold"
            :href="route('admin.admissions.index')" />

        <x-stat-card
            label="Admitted"
            :value="number_format($admissions['admitted'])"
            :hint="$admissions['not_admitted'] . ' not admitted'"
            icon="academic"
            tone="emerald"
            :href="route('admin.admissions.index')" />

        <x-stat-card
            label="Scoresheets to review"
            :value="number_format($admissions['open_imports'])"
            :hint="$admissions['exams'] . ' examination(s) on file'"
            icon="upload"
            tone="rose"
            :href="route('admin.imports.index')" />
    </div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    {{-- ================= Active examination ================= --}}
    <div class="card lg:col-span-2">
        <div class="panel-header">
            <div>
                <p class="panel-title">Current examination</p>
                <p class="mt-0.5 text-xs text-muted">Score capture progress</p>
            </div>

            @if ($activeExam)
                <x-status-pill :status="$activeExam->status" />
            @endif
        </div>

        @if ($activeExam)
            <div class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-display text-lg font-semibold text-ink">{{ $activeExam->title }}</p>
                        <p class="mt-1 text-sm text-muted">
                            {{ $activeExam->level?->name ?? 'All levels' }}
                            @if ($activeExam->exam_date)
                                · {{ $activeExam->exam_date->format('j M Y') }}
                            @endif
                            @if ($activeExam->venue)
                                · {{ $activeExam->venue }}
                            @endif
                        </p>
                    </div>

                    <a href="{{ route('admin.exams.show', $activeExam) }}" class="btn-secondary btn-sm">Open</a>
                </div>

                @if ($scoreProgress)
                    <div class="mt-6">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-ink-soft">Scores captured</span>
                            <span class="font-semibold text-ink">
                                {{ number_format($scoreProgress['captured']) }} / {{ number_format($scoreProgress['expected']) }}
                                <span class="ml-1 text-muted">({{ $scoreProgress['percent'] }}%)</span>
                            </span>
                        </div>

                        <div class="mt-2.5 h-2.5 w-full overflow-hidden rounded-full bg-surface-3">
                            <div class="h-full rounded-full bg-brand-700 transition-all"
                                 style="width: {{ min($scoreProgress['percent'], 100) }}%"></div>
                        </div>

                        <p class="mt-2.5 text-xs text-muted">
                            {{ number_format($scoreProgress['verified']) }} of them verified.
                        </p>
                    </div>
                @else
                    <p class="mt-6 rounded-xl bg-surface-2 p-4 text-sm text-ink-soft ring-1 ring-line">
                        No candidates have been registered for this examination yet.
                        <a href="{{ route('admin.exams.show', $activeExam) }}" class="font-semibold text-brand-700 dark:text-brand-200 underline decoration-brand-300 underline-offset-2">Register the candidates</a>
                        to start capturing scores.
                    </p>
                @endif

                <div class="mt-6 flex flex-wrap gap-2.5 border-t border-line pt-5">
                    <a href="{{ route('admin.scores.index', ['exam' => $activeExam->id]) }}" class="btn-secondary btn-sm">Type scores</a>
                    <a href="{{ route('admin.imports.index', ['exam' => $activeExam->id]) }}" class="btn-secondary btn-sm">Upload scoresheet</a>
                    <a href="{{ route('admin.admissions.index', ['exam' => $activeExam->id]) }}" class="btn-gold btn-sm">Cutoff &amp; decisions</a>
                </div>
            </div>
        @else
            <div class="p-5 sm:p-6">
                <x-empty-state
                    title="No examination yet"
                    description="Create an examination, add its subjects, then register the candidates to begin capturing scores."
                    icon="clipboard">
                    @can('exams.manage')
                        <a href="{{ route('admin.exams.create') }}" class="btn-primary">Create an examination</a>
                    @endcan
                </x-empty-state>
            </div>
        @endif
    </div>

    {{-- ================= Needs attention ================= --}}
    <div class="card">
        <div class="panel-header">
            <p class="panel-title">Needs attention</p>
            @if ($admissions['open_imports'] > 0)
                <span class="badge bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20">{{ $admissions['open_imports'] }} open</span>
            @endif
        </div>

        <div class="divide-y divide-line-soft">
            @forelse ($pendingImports as $import)
                <a href="{{ route('admin.imports.show', $import) }}"
                   class="block px-5 py-4 transition hover:bg-surface-2">
                    <div class="flex items-start justify-between gap-3">
                        <p class="truncate text-sm font-medium text-ink">{{ $import->original_name }}</p>
                        <x-status-pill :status="$import->status" />
                    </div>
                    <p class="mt-1 text-xs text-muted">
                        {{ $import->examSubject?->subject?->name ?? 'Whole examination' }}
                        · {{ $import->driver->label() }}
                        · {{ $import->created_at->diffForHumans() }}
                    </p>
                </a>
            @empty
                <p class="px-5 py-8 text-center text-sm text-muted">
                    Nothing is waiting on you. Uploaded scoresheets appear here until they are reviewed and committed.
                </p>
            @endforelse
        </div>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    {{-- ================= Recent applicants ================= --}}
    <div class="card">
        <div class="panel-header">
            <p class="panel-title">Latest applicants</p>
            <a href="{{ route('admin.applicants.index') }}" class="text-sm font-semibold text-brand-700 dark:text-brand-200 hover:text-brand-800 dark:text-brand-200">View all</a>
        </div>

        <div class="divide-y divide-line-soft">
            @forelse ($recentApplicants as $applicant)
                <a href="{{ route('admin.applicants.show', $applicant) }}"
                   class="flex items-center gap-3.5 px-5 py-3.5 transition hover:bg-surface-2">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-xs font-semibold text-ink-soft">
                        {{ $applicant->initials }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-ink">{{ $applicant->full_name }}</span>
                        <span class="block font-mono text-xs text-muted">{{ $applicant->registration_number }}</span>
                    </span>

                    <x-status-pill :status="$applicant->status" />
                </a>
            @empty
                <p class="px-5 py-8 text-center text-sm text-muted">No applicants have registered yet.</p>
            @endforelse
        </div>
    </div>

    {{-- ================= Recent decisions ================= --}}
    <div class="card">
        <div class="panel-header">
            <p class="panel-title">Recent decisions</p>
            <a href="{{ route('admin.admissions.index') }}" class="text-sm font-semibold text-brand-700 dark:text-brand-200 hover:text-brand-800 dark:text-brand-200">Cutoff desk</a>
        </div>

        <div class="divide-y divide-line-soft">
            @forelse ($recentDecisions as $decision)
                <div class="flex items-center gap-3.5 px-5 py-3.5">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-ink">
                            {{ $decision->applicant?->full_name ?? 'Unknown applicant' }}
                        </span>
                        <span class="block text-xs text-muted">
                            {{ $decision->exam?->title }}
                            · avg {{ rtrim(rtrim(number_format((float) $decision->average_score, 1), '0'), '.') }}%
                            (cutoff {{ rtrim(rtrim(number_format((float) $decision->cutoff_mark, 1), '0'), '.') }}%)
                        </span>
                    </span>

                    <x-status-pill :status="$decision->decision" />
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-muted">
                    No decisions have been made yet. They appear here once the cutoff is applied.
                </p>
            @endforelse
        </div>
    </div>
</div>

</x-panel-section>

{{-- ================================================================
     2. STUDENTS & RESULTS
     ================================================================ --}}
<x-panel-section number="2" title="Students & Results"
                 :meta="$term?->name"
                 description="Everyone transferred out of admissions, and how far their term report cards have progressed.">

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card
            label="Enrolled students"
            :value="number_format($students['total'])"
            :hint="$students['active'] . ' active · ' . $students['classes'] . ' classes'"
            icon="users"
            tone="brand"
            :href="route('admin.students.index')" />

        <x-stat-card
            label="Report cards computed"
            :value="number_format($students['results_computed'])"
            :hint="$term?->name ? 'For ' . $term->name : 'No current term'"
            icon="chart"
            tone="emerald"
            :href="route('admin.results.index')" />

        <x-stat-card
            label="Results published"
            :value="number_format($students['results_published'])"
            hint="Visible on the public checker"
            icon="academic"
            tone="gold"
            :href="route('admin.results.index')" />

        <x-stat-card
            label="Still to compute"
            :value="number_format($students['results_awaiting'])"
            :hint="$students['assessments'] . ' assessment(s) recorded'"
            icon="clock"
            tone="rose"
            :href="route('admin.results.index')" />
    </div>

    @if ($students['without_class'] > 0)
        <div class="mt-6">
            <x-alert tone="warning" title="Students without a class">
                {{ $students['without_class'] }} student(s) have no class arm assigned, so they will not
                appear on a class register. Assign one from the student record.
                <a href="{{ route('admin.students.index') }}" class="font-semibold underline decoration-gold-400 underline-offset-2">Review students</a>
            </x-alert>
        </div>
    @endif
</x-panel-section>

{{-- ================================================================
     3. FEES COLLECTIONS
     ================================================================ --}}
<x-panel-section number="3" title="Fees Collections"
                 :meta="$school->code . ($session ? ' · ' . $session->name : '')"
                 description="What has been billed to parents this session, what has come in, and what is still owed.">

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card
            label="Total billed"
            :value="$school->currency . number_format($fees['billed'], 2)"
            :hint="$fees['invoice_count'] . ' invoice(s) raised'"
            icon="receipt"
            tone="slate"
            :href="route('admin.invoices.index')" />

        <x-stat-card
            label="Collected"
            :value="$school->currency . number_format($fees['collected'], 2)"
            :hint="$school->currency . number_format($fees['collected_this_month'], 2) . ' this month'"
            icon="cash"
            tone="emerald"
            :href="route('admin.payments.index')" />

        <x-stat-card
            label="Outstanding"
            :value="$school->currency . number_format($fees['outstanding'], 2)"
            :hint="$fees['unpaid_count'] . ' invoice(s) unpaid'"
            icon="chart"
            tone="rose"
            :href="route('admin.invoices.index', ['status' => 'unpaid'])" />

        <x-stat-card
            label="Collection rate"
            :value="rtrim(rtrim(number_format($fees['rate'], 1), '0'), '.') . '%'"
            :hint="$fees['fully_paid_count'] . ' invoice(s) fully paid'"
            icon="scale"
            tone="gold"
            :href="route('admin.invoices.index')" />
    </div>

    @if ($fees['billed'] > 0)
        <div class="card-pad mt-6">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-ink-soft">Collected against billed</span>
                <span class="font-semibold text-ink">
                    {{ $school->currency }}{{ number_format($fees['collected'], 2) }}
                    <span class="text-muted">of {{ $school->currency }}{{ number_format($fees['billed'], 2) }}</span>
                </span>
            </div>

            <div class="mt-3 h-3 w-full overflow-hidden rounded-full bg-surface-3">
                <div class="h-full rounded-full bg-emerald-500 transition-all"
                     style="width: {{ min($fees['rate'], 100) }}%"></div>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="panel-header">
                <p class="panel-title">Largest outstanding balances</p>
                <a href="{{ route('admin.invoices.index', ['status' => 'unpaid']) }}" class="text-sm font-semibold text-brand-700 dark:text-brand-200 hover:text-brand-800 dark:text-brand-200">All invoices</a>
            </div>

            <div class="divide-y divide-line-soft">
                @forelse ($topDebtors as $invoice)
                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="flex items-center gap-3.5 px-5 py-3.5 transition hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink">
                                {{ $invoice->student?->full_name ?? 'Unknown student' }}
                            </span>
                            <span class="block font-mono text-xs text-muted">
                                {{ $invoice->student?->student_number }} · {{ $invoice->invoice_number }}
                            </span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="block text-sm font-semibold text-rose-600 dark:text-rose-400">
                                {{ $school->currency }}{{ number_format((float) $invoice->balance, 2) }}
                            </span>
                        </span>
                    </a>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-muted">
                        Nothing outstanding — every invoice raised has been paid.
                    </p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="panel-header">
                <p class="panel-title">Recent payments</p>
                <a href="{{ route('admin.payments.index') }}" class="text-sm font-semibold text-brand-700 dark:text-brand-200 hover:text-brand-800 dark:text-brand-200">All payments</a>
            </div>

            <div class="divide-y divide-line-soft">
                @forelse ($recentPayments as $payment)
                    <div class="flex items-center gap-3.5 px-5 py-3.5">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink">
                                {{ $payment->student?->full_name ?? 'Unknown student' }}
                            </span>
                            <span class="block font-mono text-xs text-muted">
                                {{ $payment->receipt_number }} · {{ $payment->methodLabel() }}
                            </span>
                        </span>
                        <span class="shrink-0 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                            {{ $school->currency }}{{ number_format((float) $payment->amount, 2) }}
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-muted">
                        No payments recorded yet. They appear here as the bursary receives money.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</x-panel-section>

@endsection
