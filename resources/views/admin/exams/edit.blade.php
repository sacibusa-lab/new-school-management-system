@extends('layouts.admin')

@section('title', 'Edit examination')
@section('subtitle', $exam->displayTitle())

@section('actions')
    <a href="{{ route('admin.exams.show', $exam) }}" class="btn-ghost btn-sm">Back to examination</a>
@endsection

@section('content')

@if ($errors->any())
    <x-alert tone="danger" title="Some details need fixing" class="mb-6">
        <ul class="list-inside list-disc space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

@if ($exam->scores()->whereNotNull('score')->exists())
    <x-alert tone="warning" class="mb-6">
        Marks have already been captured for this examination. Changing the cutoff mark here only
        affects the merit list — nothing is re-marked — but you must run
        <strong>Compute</strong> again on the Cutoff &amp; decisions desk for the change to take effect.
    </x-alert>
@endif

<form method="POST" action="{{ route('admin.exams.update', $exam) }}"
      x-data="{ status: @js(old('status', $exam->status->value)) }">
    @csrf
    @method('PUT')

    {{-- ================= Details ================= --}}
    <div class="card-pad">
        <h2 class="font-display text-lg font-semibold text-ink">Examination details</h2>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-field name="title" label="Title" required :value="$exam->title"
                         placeholder="Entrance Examination 2026/2027" />
            </div>

            <x-field name="academic_session_id" label="Academic session" type="select" required
                     :value="$exam->academic_session_id"
                     :options="$sessions->pluck('name', 'id')->all()" />

            <x-field name="level_id" label="Class sitting the examination" type="select" required
                     placeholder-option="Choose a class"
                     :value="$exam->level_id"
                     :options="$levels->pluck('name', 'id')->all()" />

            <x-field name="exam_date" label="Examination date" type="date"
                     :value="$exam->exam_date?->toDateString()" />

            <x-field name="starts_at" label="Start time" type="time"
                     :value="$exam->starts_at ? \Illuminate\Support\Str::of($exam->starts_at)->substr(0, 5)->value() : null" />

            <x-field name="venue" label="Venue" :value="$exam->venue" placeholder="Main hall" />

            <x-field name="cutoff_mark" label="Cutoff mark (%)" type="number"
                     :value="$exam->cutoff_mark" min="0" max="100" step="0.01"
                     hint="Overridden by the level cutoff if one is set." />
        </div>

        <div class="mt-4">
            <x-field name="instructions" label="Instructions for candidates" type="textarea"
                     :value="$exam->instructions"
                     placeholder="Report to the main hall by 7:30am with your registration slip." />
        </div>
    </div>

    {{-- ================= Status ================= --}}
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Where this examination has got to</h2>
        <p class="mt-1 text-sm text-muted">
            The status decides what can still be changed. Marking and publishing lock the marks down.
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <x-field name="status" label="Status" type="select" required
                     :value="$exam->status->value"
                     :options="$statuses"
                     x-model="status" />

            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm text-ink-soft">
                    <input type="checkbox" name="results_locked" value="1"
                           @checked(old('results_locked', $exam->results_locked))
                           class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                    Lock the marks
                    <span class="text-muted">(no further score edits)</span>
                </label>
            </div>
        </div>

        <div x-show="status === 'published'" x-cloak
             class="mt-4 rounded-xl bg-gold-50 dark:bg-gold-950/40 p-4 text-sm text-gold-900 ring-1 ring-inset ring-gold-600/20 dark:ring-gold-400/20">
            Publishing makes the examination visible to applicants. It is stamped with the date you publish
            and is not un-published by changing the status back.
        </div>

        @if ($exam->published_at)
            <p class="mt-4 text-xs text-muted">
                First published {{ $exam->published_at->format('j F Y, g:ia') }}.
            </p>
        @endif
    </div>

    {{-- ================= Subjects note ================= --}}
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Subjects and candidates</h2>
        <p class="mt-1 text-sm text-muted">
            These are managed on the examination page, because adding a subject has to create a blank mark
            for every candidate — that is not something to do by accident while editing the title.
        </p>

        <div class="mt-4 flex flex-wrap gap-3">
            <a href="{{ route('admin.exams.show', $exam) }}" class="btn-secondary btn-sm">
                Manage subjects &amp; candidates
            </a>
            <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-ghost btn-sm">
                Type scores
            </a>
        </div>
    </div>

    {{-- ================= Save ================= --}}
    <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
        <p class="text-sm text-muted">
            Created {{ $exam->created_at->format('j F Y') }}
            @if ($exam->creator) by {{ $exam->creator->name }} @endif.
        </p>

        <div class="ml-auto flex items-center gap-3">
            <a href="{{ route('admin.exams.show', $exam) }}" class="btn-ghost btn-sm">Cancel</a>
            <button type="submit" class="btn-primary btn-sm">Save changes</button>
        </div>
    </div>
</form>

{{-- ================= Danger zone ================= --}}
@can('delete', $exam)
    <div class="card-pad mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Delete this examination</h2>

        @if ($exam->decisions()->exists())
            <p class="mt-1 text-sm text-muted">
                This examination has admission decisions attached, so it cannot be deleted —
                deleting it would orphan those decisions. Set its status to withdrawn instead.
            </p>
        @else
            <p class="mt-1 text-sm text-muted">
                This also removes every mark captured against it. Only possible while no admission
                decisions have been made from it.
            </p>

            <form method="POST" action="{{ route('admin.exams.destroy', $exam) }}" class="mt-4"
                  onsubmit="return confirm('Delete this examination and all of its captured marks? This cannot be undone.')">
                @csrf
                @method('DELETE')

                <button type="submit" class="btn-danger btn-sm">Delete examination</button>
            </form>
        @endif
    </div>
@endcan

@endsection
