@extends('layouts.admin')

@section('title', 'Score entry')
@section('subtitle', 'Choose the class and the batch, then type the marks')

@section('content')

@php
    $row = fn ($examSubject) => $progress[$examSubject->id] ?? null;
    $total = fn ($examSubject) => (int) ($row($examSubject)->total ?? 0);
    $captured = fn ($examSubject) => (int) ($row($examSubject)->captured ?? 0);
@endphp

{{-- ================= Class and batch ================= --}}
{{-- Two choices, then the names. Changing the class re-submits so its batches
     appear, and drops the batch that belonged to the class we just left. --}}
<form method="GET" class="card-pad">
    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-40 flex-1">
            <label for="level" class="label">Class</label>
            <select id="level" name="level" class="input"
                    onchange="document.getElementById('exam').value = ''; this.form.submit()">
                @forelse ($levels as $level)
                    <option value="{{ $level->id }}" @selected((int) $levelId === $level->id)>
                        {{ $level->name }}
                    </option>
                @empty
                    <option value="">No classes set up</option>
                @endforelse
            </select>
        </div>

        <div class="min-w-56 flex-[2]">
            <label for="exam" class="label">Batch</label>
            <select id="exam" name="exam" class="input" @disabled($batches->isEmpty())
                    onchange="this.form.submit()">
                @forelse ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected($selectedExam?->id === $batch->id)>
                        {{ $batch->title }}@if ($batch->exam_date) — {{ $batch->exam_date->format('j M Y') }}@endif
                    </option>
                @empty
                    <option value="">No batch for this class yet</option>
                @endforelse
            </select>
            <p class="hint">One batch is one sitting of the examination.</p>
        </div>

        <button type="submit" class="btn-primary btn-sm">Show the names</button>
    </div>
</form>

@if (! $selectedExam)
    <div class="mt-6">
        <x-empty-state
            title="No batch to mark yet"
            description="A batch is one sitting of the examination. Create one for this class, register its candidates, and the names will appear here ready for their marks."
            icon="pencil">
            @can('exams.manage')
                <a href="{{ route('admin.exams.create') }}" class="btn-primary">Create an examination</a>
            @endcan
        </x-empty-state>
    </div>
@else

    @if (($awaitingVerification ?? 0) > 0)
        {{-- Marks read off a photograph are a guess until a person agrees with
             them, so this goes in front of the grid. --}}
        <a href="{{ route('admin.scores.verify', $selectedExam) }}"
           class="card-pad mt-6 block bg-gold-500 transition hover:bg-gold-400">
            <p class="font-display text-sm font-semibold text-gold-950">
                {{ $awaitingVerification }} mark(s) on this batch are waiting to be verified
            </p>
            <p class="mt-1 text-xs text-gold-900">
                They were read from a sheet by machine. Check them against the scripts and sign them
                off before they count.
            </p>
        </a>
    @endif

    <div class="mt-6">
        @include('admin.scores.partials.grid')
    </div>

    @if ($examSubjects->isNotEmpty() && $candidates->isNotEmpty())
        {{-- Capture progress, kept out of the way: the grid above is the job, and
             this is only here to answer "how much is left?". --}}
        <details class="card-pad mt-6">
            <summary class="cursor-pointer text-sm font-medium text-slate-700">
                Captured so far, paper by paper
            </summary>

            <div class="mt-4 divide-y divide-slate-100">
                @foreach ($examSubjects as $examSubject)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $examSubject->subject?->name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $captured($examSubject) }}/{{ $total($examSubject) }} captured
                                @if ((int) ($row($examSubject)->absent ?? 0) > 0)
                                    · {{ (int) $row($examSubject)->absent }} absent
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <span @class([
                                'badge',
                                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $total($examSubject) > 0 && $captured($examSubject) === $total($examSubject),
                                'bg-gold-50 text-gold-700 ring-gold-600/20' => $total($examSubject) === 0 || $captured($examSubject) < $total($examSubject),
                            ])>
                                {{ $total($examSubject) > 0 ? (int) round(($captured($examSubject) / $total($examSubject)) * 100) : 0 }}%
                            </span>

                            <a href="{{ route('admin.scores.entry', [$selectedExam, $examSubject]) }}"
                               class="text-xs font-medium text-brand-700 hover:text-brand-900">
                                Mark this paper alone
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
@endif

@endsection

@include('admin.scores.partials.grid-scripts')
