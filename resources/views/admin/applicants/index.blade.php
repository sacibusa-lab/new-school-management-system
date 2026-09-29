@extends('layouts.admin')

@section('title', 'Applicants')
@section('subtitle', 'Everyone who has registered for admission')

@section('actions')
    @can('admissions.create')
        <a href="{{ route('admin.applicants.import') }}" class="btn-secondary btn-sm">Bulk upload</a>
        <a href="{{ route('admin.applicants.photos') }}" class="btn-secondary btn-sm">Upload photos</a>
        <a href="{{ route('admin.applicants.create') }}" class="btn-primary btn-sm">Register applicant</a>
    @endcan
@endsection
@section('content')

{{-- ================= Status overview ================= --}}
<div class="grid gap-3 sm:grid-cols-4 xl:grid-cols-7">
    @foreach ($statuses as $value => $label)
        @php $count = (int) ($counts[$value] ?? 0); @endphp
        <a href="{{ route('admin.applicants.index', array_filter(['status' => $value, 'q' => request('q'), 'level' => request('level')])) }}"
           @class([
               'card p-4 transition hover:shadow-lift',
               'ring-2 ring-brand-600' => request('status') === $value,
           ])>
            <p class="font-display text-xl font-semibold text-ink">{{ number_format($count) }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $label }}</p>
        </a>
    @endforeach
</div>

{{-- ================= The papers the office hands out ================= --}}
@if ($exams->isNotEmpty() && auth()->user()?->can('admissions.view'))
    <div class="card-pad mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-ink">Admission papers</p>
                <p class="mt-0.5 text-xs text-muted">
                    The merit list and the admission letters for this session's examinations, ready to print.
                </p>
            </div>
        </div>

        <ul class="mt-4 divide-y divide-line-soft">
            @foreach ($exams as $paperExam)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink">{{ $paperExam->title }}</p>
                        <p class="text-xs text-muted">
                            {{ $paperExam->level?->name ?? 'All classes' }}
                            @if ($paperExam->exam_date)
                                · {{ $paperExam->exam_date->format('j M Y') }}
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a href="{{ route('admin.admissions.merit', $paperExam) }}" class="btn-secondary btn-sm">Merit list</a>
                        @can('admissions.letters')
                            <a href="{{ route('admin.admissions.letters', $paperExam) }}" class="btn-secondary btn-sm">Letters</a>
                        @endcan
                        @can('admissions.decide')
                            <a href="{{ route('admin.admissions.index', ['exam' => $paperExam->id]) }}" class="btn-ghost btn-sm">
                                Cutoff desk
                            </a>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- ================= Filters ================= --}}
<form method="GET" class="card-pad mt-6">
    @if (request('status'))
        <input type="hidden" name="status" value="{{ request('status') }}">
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-field name="q" label="Search" placeholder="Name, registration number or phone"
                     :value="request('q')" />
        </div>

        <x-field name="level" label="Class" type="select"
                 placeholder-option="All classes"
                 :value="request('level')"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="session" label="Session" type="select"
                 placeholder-option="All sessions"
                 :value="request('session')"
                 :options="$sessions->pluck('name', 'id')->all()" />
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary btn-sm">Apply filters</button>

        @if (request()->hasAny(['q', 'level', 'session', 'status']))
            <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Clear</a>
        @endif

        @can('admissions.export')
            {{-- Carries the current filters, so the file matches what is on screen. --}}
            <a href="{{ route('admin.applicants.export', request()->query()) }}" class="btn-secondary btn-sm">
                Export as CSV
            </a>
        @endcan

        <p class="ml-auto text-xs text-muted">
            {{ number_format($applicants->total()) }} record(s)
        </p>
    </div>
</form>

{{-- ================= Table ================= --}}
<div class="table-wrap mt-6">
    <table class="table">
        <thead>
            <tr>
                <th>Registration no.</th>
                <th>Applicant</th>
                <th>Class</th>
                <th>Contact</th>
                <th class="text-center">Scores</th>
                <th>Status</th>
                <th>Registered</th>
                <th></th>
            </tr>
        </thead>

        <tbody>
            @forelse ($applicants as $applicant)
                <tr>
                    <td class="font-mono text-xs font-medium text-ink">{{ $applicant->registration_number }}</td>

                    <td>
                        <div class="flex items-center gap-3">
                            {{-- The photograph if there is one, otherwise the initials: an
                                 empty frame on every row would say nothing. --}}
                            @if ($applicant->photo_path)
                                <img src="{{ asset('storage/' . $applicant->photo_path) }}"
                                     alt="" loading="lazy"
                                     class="h-8 w-8 shrink-0 rounded-lg object-cover ring-1 ring-line">
                            @else
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-[11px] font-semibold text-ink-soft">
                                    {{ $applicant->initials }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                <p class="truncate font-medium text-ink">{{ $applicant->full_name }}</p>
                                <p class="text-xs text-muted">
                                    {{ $applicant->gender?->label() }}
                                    @if ($applicant->age) · {{ $applicant->age }} yrs @endif
                                </p>
                            </div>
                        </div>
                    </td>

                    <td class="text-sm">{{ $applicant->levelAppliedFor?->name ?? '—' }}</td>

                    <td class="text-sm">
                        <p class="text-ink-soft">{{ $applicant->phone ?? '—' }}</p>
                        @if ($applicant->email)
                            <p class="truncate text-xs text-muted">{{ $applicant->email }}</p>
                        @endif
                    </td>

                    <td class="text-center text-sm">{{ $applicant->scores_count }}</td>

                    <td><x-status-pill :status="$applicant->status" /></td>

                    <td class="text-xs text-muted">
                        {{ $applicant->created_at->format('j M Y') }}
                    </td>

                    <td class="text-right">
                        <a href="{{ route('admin.applicants.show', $applicant) }}" class="btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-16 text-center">
                        <p class="text-sm font-medium text-ink">No applicants match these filters</p>
                        <p class="mt-1 text-sm text-muted">
                            Try clearing the filters, or share the application link with prospective parents.
                        </p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">
    {{ $applicants->links() }}
</div>

@endsection
