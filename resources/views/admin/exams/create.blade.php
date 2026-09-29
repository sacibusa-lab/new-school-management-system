@extends('layouts.admin')

@section('title', 'New examination')
@section('subtitle', 'Set up the paper and the subjects being examined')

@section('content')
<form method="POST" action="{{ route('admin.exams.store') }}" class="grid gap-6 lg:grid-cols-3">
    @csrf

    <div class="space-y-6 lg:col-span-2">
        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">Examination details</h2>

            <div class="mt-6 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-field name="title" label="Title" required
                             placeholder="Entrance Examination 2026/2027" />
                </div>

                <x-field name="academic_session_id" label="Academic session" type="select" required
                         :options="$sessions->pluck('name', 'id')->all()" />

                <x-field name="level_id" label="Class sitting the exam" type="select" required
                         placeholder-option="Choose a class"
                         :options="$levels->pluck('name', 'id')->all()" />

                <x-field name="exam_date" label="Examination date" type="date" />
                <x-field name="starts_at" label="Start time" type="time" />
                <x-field name="venue" label="Venue" placeholder="Main hall" />
                <x-field name="status" label="Status" type="select" required
                         :value="'draft'"
                         :options="$statuses" />
            </div>
        </div>

        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">Subjects examined</h2>
            <p class="mt-1 text-sm text-muted">
                Every candidate sits all selected subjects. You can add or remove subjects later.
            </p>

            @error('subjects')
                <p class="error-text mt-3">{{ $message }}</p>
            @enderror

            @php $selectedSubjects = old('subjects', $standardPapers); @endphp

            <div class="mt-5 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($subjects as $subject)
                    @php $isStandard = in_array($subject->id, $standardPapers, true); @endphp

                    <label @class([
                        'flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-3 transition',
                        'border-gold-300 bg-gold-50/50 hover:border-gold-400' => $isStandard,
                        'border-line hover:border-brand-300 hover:bg-brand-50/40' => ! $isStandard,
                    ])>
                        <input type="checkbox" name="subjects[]" value="{{ $subject->id }}"
                               @checked(in_array($subject->id, $selectedSubjects, true))
                               class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-ink-soft">{{ $subject->name }}</span>
                            @if ($isStandard)
                                <span class="block text-[11px] font-medium uppercase tracking-wider text-gold-700 dark:text-gold-300">
                                    Entrance paper
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-muted">
                The entrance papers your school sits
                ({{ \App\Models\Subject::query()->whereIn('id', $standardPapers)->pluck('name')->implode(', ') ?: 'none configured' }})
                are ticked for you. Change them in
                <a href="{{ route('admin.settings.index') }}" class="font-semibold text-brand-700 dark:text-brand-200 underline decoration-brand-300 underline-offset-2">Settings</a>
                if your entrance papers change.
            </p>
        </div>

        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">Marking scheme</h2>

            <div class="mt-6 grid gap-5 sm:grid-cols-3">
                <x-field name="total_marks" label="Marks per subject" type="number" required
                         value="100" min="1" max="1000"
                         hint="Applies to each subject." />

                <x-field name="pass_mark" label="Subject pass mark" type="number"
                         placeholder="40" min="0"
                         hint="Leave blank to use 40%." />

                <x-field name="cutoff_mark" label="Cutoff mark (%)" type="number"
                         placeholder="50" min="0" max="100" step="0.01"
                         hint="Overridden by the level cutoff if one is set." />
            </div>
        </div>

        <div class="card-pad">
            <x-field name="instructions" label="Instructions for candidates" type="textarea"
                     placeholder="Report to the main hall by 7:30am with your registration slip." />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary btn-lg">Create examination</button>
            <a href="{{ route('admin.exams.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </div>

    {{-- ================= Guidance ================= --}}
    <aside class="space-y-6">
        <div class="card-pad">
            <h3 class="text-sm font-semibold text-ink">What happens next</h3>
            <ol class="mt-4 space-y-4">
                @foreach ([
                    'Register the candidates — this creates a blank score row for every candidate and subject.',
                    'Capture the marks: type them, upload a spreadsheet, or upload a photo of the marked sheet for AI to read.',
                    'Compute the merit list, then apply the cutoff mark.',
                    'Transfer the successful applicants into the results and fees portals.',
                ] as $index => $step)
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-3 text-xs font-semibold text-ink-soft">
                            {{ $index + 1 }}
                        </span>
                        <span class="text-sm text-ink-soft">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="card-pad bg-brand-50/60">
            <h3 class="text-sm font-semibold text-brand-900 dark:text-brand-100">Tip</h3>
            <p class="mt-2 text-sm text-brand-800 dark:text-brand-200">
                Set the status to <strong>Draft</strong> while you are preparing. Switch it to
                <strong>Scheduled</strong> once the date is confirmed so it appears on the public website.
            </p>
        </div>
    </aside>
</form>
@endsection
