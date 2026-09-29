@extends('layouts.admin')

@section('title', 'Students')
@section('subtitle', 'Admitted applicants — in the results and fees portals')

@section('content')

{{-- ================= Filters ================= --}}
<form method="GET" class="card-pad">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-field name="q" label="Search" placeholder="Admission number, registration number or name"
                     :value="request('q')" />
        </div>

        <x-field name="level" label="Class" type="select"
                 placeholder-option="All classes"
                 :value="request('level')"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="session" label="Admitted in" type="select"
                 placeholder-option="All sessions"
                 :value="request('session')"
                 :options="$sessions->pluck('name', 'id')->all()" />
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary btn-sm">Apply filters</button>

        <a href="{{ route('admin.students.index', ['owing' => 1]) }}"
           @class(['btn-sm', request('owing') ? 'btn-primary' : 'btn-secondary'])>
            Owing only
        </a>

        @if (request()->hasAny(['q', 'level', 'session', 'owing']))
            <a href="{{ route('admin.students.index') }}" class="btn-ghost btn-sm">Clear</a>
        @endif

        <p class="ml-auto text-xs text-muted">{{ number_format($students->total()) }} student(s)</p>
    </div>
</form>

{{-- ================= Table ================= --}}
<div class="table-wrap mt-6">
    <table class="table">
        <thead>
            <tr>
                <th>Admission number</th>
                <th>Student</th>
                <th>Class</th>
                <th>Registration no.</th>
                <th class="text-center">Invoices</th>
                <th class="text-right">Balance</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>

        <tbody>
            @forelse ($students as $student)
                <tr>
                    <td>
                        <p class="font-mono text-xs font-semibold text-brand-800 dark:text-brand-200">{{ $student->student_number }}</p>
                        <p class="text-xs text-muted">{{ $student->admitted_at?->format('j M Y') }}</p>
                    </td>

                    <td>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/30 text-[11px] font-semibold text-brand-800 dark:text-brand-200">
                                {{ $student->initials }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-ink">{{ $student->full_name }}</p>
                                <p class="text-xs text-muted">
                                    {{ $student->gender?->label() }}
                                    @if ($student->admission_average)
                                        · admitted on {{ rtrim(rtrim(number_format((float) $student->admission_average, 1), '0'), '.') }}%
                                    @endif
                                </p>
                            </div>
                        </div>
                    </td>

                    <td class="text-sm">
                        {{ $student->schoolClass?->name ?? '—' }}
                        <p class="text-xs text-muted">{{ $student->level?->name }}</p>
                    </td>

                    <td class="font-mono text-xs text-muted">{{ $student->admission_number ?? '—' }}</td>

                    <td class="text-center text-sm">{{ $student->invoices_count }}</td>

                    <td class="text-right text-sm font-medium {{ ($student->balance_due ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">
                        {{ $school->currency }}{{ number_format((float) ($student->balance_due ?? 0), 2) }}
                    </td>

                    <td><x-status-pill :status="$student->status" /></td>

                    <td class="text-right">
                        <a href="{{ route('admin.students.show', $student) }}" class="btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-16 text-center">
                        <p class="text-sm font-medium text-ink">No students yet</p>
                        <p class="mt-1 text-sm text-muted">
                            Students appear here automatically once you transfer admitted applicants
                            from the cutoff desk.
                        </p>
                        <a href="{{ route('admin.admissions.index') }}" class="btn-primary mt-5">Go to the cutoff desk</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">{{ $students->links() }}</div>

@endsection
