@extends('layouts.admin')

@section('title', 'Examinations')
@section('subtitle', 'Entrance and internal examinations')

@section('actions')
    @can('exams.manage')
        <a href="{{ route('admin.exams.create') }}" class="btn-primary btn-sm">New examination</a>
    @endcan
@endsection

@section('content')

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Examination</th>
                <th>Class</th>
                <th>Date</th>
                <th class="text-center">Subjects</th>
                <th class="text-center">Score rows</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>

        <tbody>
            @forelse ($exams as $exam)
                <tr>
                    <td>
                        <div class="flex flex-wrap items-center gap-2">
                            {{-- Resits carry their round so two sittings never look identical. --}}
                            <p class="font-medium text-slate-900">{{ $exam->displayTitle() }}</p>

                            @if ($exam->is_resit)
                                <span class="badge bg-gold-50 text-gold-700 ring-gold-600/20">Resit</span>
                            @endif
                        </div>

                        <p class="text-xs text-slate-500">{{ $exam->academicSession?->name }}</p>
                    </td>

                    <td class="text-sm">{{ $exam->level?->name ?? 'All levels' }}</td>

                    <td class="text-sm">
                        {{ $exam->exam_date?->format('j M Y') ?? '—' }}
                        @if ($exam->starts_at)
                            <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::of($exam->starts_at)->substr(0, 5) }}</p>
                        @endif
                    </td>

                    <td class="text-center text-sm">{{ $exam->exam_subjects_count }}</td>
                    <td class="text-center text-sm">{{ number_format($exam->scores_count) }}</td>

                    <td><x-status-pill :status="$exam->status" /></td>

                    <td class="text-right">
                        <a href="{{ route('admin.exams.show', $exam) }}" class="btn-ghost btn-sm">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-16 text-center">
                        <p class="text-sm font-medium text-slate-900">No examinations yet</p>
                        <p class="mt-1 text-sm text-slate-500">
                            Create the entrance examination, add its subjects, then register the candidates.
                        </p>
                        @can('exams.manage')
                            <a href="{{ route('admin.exams.create') }}" class="btn-primary mt-5">Create an examination</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">{{ $exams->links() }}</div>

@endsection
