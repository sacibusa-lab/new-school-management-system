@extends('layouts.admin')

@section('title', 'Score entry')
@section('subtitle', 'Type marks straight from the marked scripts')

@section('content')

@if ($exams->isEmpty())
    <x-empty-state
        title="No examination is open for marking"
        description="Create an examination and register its candidates first, then come back to capture the marks."
        icon="pencil">
        @can('exams.manage')
            <a href="{{ route('admin.exams.create') }}" class="btn-primary">Create an examination</a>
        @endcan
    </x-empty-state>
@else
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ================= Pick an examination ================= --}}
        <div class="lg:col-span-2">
            <div class="card">
                <div class="panel-header">
                    <div>
                        <p class="panel-title">Examinations open for marking</p>
                        <p class="mt-0.5 text-xs text-slate-500">Choose an examination to see its subjects</p>
                    </div>
                </div>

                <div class="divide-y divide-slate-100">
                    @foreach ($exams as $item)
                        @php $selected = $selectedExam && $selectedExam->id === $item->id; @endphp

                        <a href="{{ route('admin.scores.index', ['exam' => $item->id]) }}"
                           @class([
                               'block px-5 py-4 transition',
                               'bg-brand-50/70' => $selected,
                               'hover:bg-slate-50' => ! $selected,
                           ])>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ $item->title }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ $item->level?->name ?? 'All levels' }}
                                        @if ($item->exam_date) · {{ $item->exam_date->format('j M Y') }} @endif
                                        · {{ $item->examSubjects->count() }} subject(s)
                                    </p>
                                </div>

                                <x-status-pill :status="$item->status" />
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ================= Subjects ================= --}}
        <aside>
            <div class="card">
                <div class="panel-header">
                    <p class="panel-title">Subjects</p>
                </div>

                @if ($selectedExam && $subjects->isNotEmpty())
                    <div class="divide-y divide-slate-100">
                        @foreach ($subjects as $examSubject)
                            @php
                                $total = $examSubject->scores()->count();
                                $captured = $examSubject->scores()->whereNotNull('score')->count();
                            @endphp

                            <a href="{{ route('admin.scores.entry', [$selectedExam, $examSubject]) }}"
                               class="flex items-center justify-between gap-3 px-5 py-3.5 transition hover:bg-slate-50">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900">
                                        {{ $examSubject->subject?->name }}
                                    </span>
                                    <span class="block text-xs text-slate-500">
                                        {{ $captured }}/{{ $total }} captured
                                    </span>
                                </span>

                                <span @class([
                                    'badge',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $total > 0 && $captured === $total,
                                    'bg-gold-50 text-gold-700 ring-gold-600/20' => $total === 0 || $captured < $total,
                                ])>
                                    {{ $total > 0 ? (int) round(($captured / $total) * 100) : 0 }}%
                                </span>
                            </a>
                        @endforeach
                    </div>
                @elseif ($selectedExam)
                    <p class="px-5 py-8 text-center text-sm text-slate-500">
                        This examination has no subjects yet.
                    </p>
                @else
                    <p class="px-5 py-8 text-center text-sm text-slate-500">
                        Select an examination to see its subjects.
                    </p>
                @endif
            </div>

            <div class="card-pad mt-6 bg-brand-50/60">
                <h3 class="text-sm font-semibold text-brand-900">Prefer to upload instead?</h3>
                <p class="mt-2 text-sm text-brand-800">
                    If the marks are already in a spreadsheet, or written on paper, upload the sheet
                    and the system will read it for you — you just review and approve.
                </p>
                <a href="{{ route('admin.imports.index') }}" class="btn-primary btn-sm mt-4">Upload a scoresheet</a>
            </div>
        </aside>
    </div>
@endif

@endsection
