@php
    // Two ticks per cell unless the cell is a settled, verified mark, in which
    // case the cell is read-only for anybody without the override permission.
    $isLocked = fn ($cell) => $cell && $cell->isVerified() && ! $canOverride;
@endphp

{{--
    The mark grid for one batch. Rendered by the score entry landing screen and by
    the focused grid screen, so a mark cannot look editable on one and not the other.

    Expects: $exam, $examSubjects, $candidates, $cells, $gradeScale, $editable,
             $canOverride, $mayVerify
--}}

@if (! ($canEnter ?? true))
    <x-alert tone="warning" class="mb-6">
        You can read these marks but not change them. Entering marks needs the score entry
        permission.
    </x-alert>
@elseif (! $editable)
    <x-alert tone="warning" class="mb-6">
        This examination is locked, so marks can be viewed but not changed.
    </x-alert>
@endif

@if ($examSubjects->isEmpty())
    <x-empty-state
        title="This examination has no papers yet"
        description="Add the subjects being examined before entering marks."
        icon="list">
        <a href="{{ route('admin.exams.show', $exam) }}" class="btn-primary">Add subjects</a>
    </x-empty-state>
@elseif ($candidates->isEmpty())
    <x-empty-state
        title="No candidates registered"
        description="Register the candidates for this examination first — that creates a mark slot for each of them."
        icon="users">
        @can('exams.manage')
            <form method="POST" action="{{ route('admin.exams.candidates.sync', $exam) }}">
                @csrf
                <button type="submit" class="btn-primary">Register candidates now</button>
            </form>
        @endcan
    </x-empty-state>
@else

<x-alert tone="info" class="mb-6">
    <p>
        Type each mark out of the paper total. Leave a box empty if the script is not yet marked,
        or type <strong class="font-mono">A</strong> for a candidate who was absent.
    </p>
    <p class="mt-1">
        You can copy a column of marks from Excel and paste it into any box — the values fill
        downwards from there.
    </p>
    <p class="mt-1">
        <strong>Total</strong> adds each paper up as a percentage of its own total, and
        <strong>Average</strong> is over the papers that have a mark — the same two figures the
        cutoff is applied to. A blank paper is not a zero, and an absence is not a zero either.
    </p>
</x-alert>

@if ($errors->any())
    <x-alert tone="danger" title="Some marks were not saved" class="mb-6">
        <p>
            Nothing was written — fix the boxes marked in red and save again.
        </p>
    </x-alert>
@endif

<form method="POST" action="{{ route('admin.scores.grid.store', $exam) }}">
    @csrf

    <div class="card overflow-hidden"
         x-data="scoreGrid(@js($gradeScale->map(fn ($g) => ['min' => (float) $g->min_score, 'grade' => $g->grade])->values()))"
         @input="onInput($event)"
         @paste="onPaste($event)"
         @keydown="onKeydown($event)">

        <div class="panel-header">
            <div>
                <p class="panel-title">{{ $exam->displayTitle() }}</p>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $candidates->count() }} candidate(s) across {{ $examSubjects->count() }} paper(s)
                </p>
            </div>

            <div class="flex items-center gap-3">
                <p class="hidden text-xs text-slate-500 sm:block">
                    <span x-text="savedCount"></span> box(es) changed
                </p>
                <button type="submit" class="btn-primary btn-sm" @disabled(! $editable)>Save all marks</button>
            </div>
        </div>

        {{-- Search narrows the rows without touching what has been typed: the form
             still submits every box, including the hidden ones. --}}
        <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <label for="candidate-filter" class="sr-only">Find a candidate</label>
                <input id="candidate-filter" type="search" placeholder="Find a candidate by name or number"
                       @input="filter = $event.target.value; applyFilter()"
                       class="input max-w-xs py-2 text-sm">

                <p class="text-xs text-slate-500" x-show="filter !== ''" x-cloak>
                    Showing <span x-text="visibleRows"></span> of {{ $candidates->count() }}
                </p>

                <p class="ml-auto text-xs text-slate-500">
                    Passing mark:
                    @foreach ($examSubjects as $i => $s)
                        {{ $i > 0 ? ' · ' : '' }}{{ $s->subject?->name }}
                        <span class="font-medium text-slate-700">{{ rtrim(rtrim(number_format((float) $s->effectivePassMark(), 2), '0'), '.') }}%</span>
                    @endforeach
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table score-grid">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-white">Candidate</th>

                        @foreach ($examSubjects as $index => $examSubject)
                            <th class="w-40 text-center" data-column="{{ $index + 1 }}">
                                <span class="block text-slate-900">{{ $examSubject->subject?->name }}</span>
                                <span class="block font-normal text-slate-400">
                                    out of {{ rtrim(rtrim(number_format((float) $examSubject->total_marks, 2), '0'), '.') }}
                                </span>
                            </th>
                        @endforeach

                        {{-- What the cutoff will rank on, so the officer sees it while
                             typing rather than after running the cutoff. --}}
                        <th class="w-24 text-right">Total</th>
                        <th class="w-24 text-right">Average</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($candidates as $rowIndex => $candidate)
                        @php $summary = $summaries[$candidate->id] ?? ['total' => 0.0, 'average' => 0.0, 'marked' => 0]; @endphp

                        <tr data-row="{{ $rowIndex }}"
                            data-cutoff="{{ (float) $exam->cutoff_mark }}"
                            data-match="{{ strtolower($candidate->full_name . ' ' . $candidate->registration_number) }}">
                            <td class="sticky left-0 z-10 bg-white">
                                <p class="font-medium text-slate-900">{{ $candidate->full_name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $candidate->registration_number }}</p>
                            </td>

                            @foreach ($examSubjects as $columnIndex => $examSubject)
                                @php
                                    $cell = ($cells[$candidate->id] ?? collect())->get($examSubject->id);
                                    $locked = $isLocked($cell);
                                @endphp

                                <td class="align-top pb-1">
                                    @if ($cell)
                                        <input type="text"
                                               inputmode="decimal"
                                               autocomplete="off"
                                               name="scores[{{ $cell->id }}]"
                                               value="{{ old('scores.' . $cell->id, $cell->is_absent ? 'A' : $cell->score) }}"
                                               data-row="{{ $rowIndex }}"
                                               data-column="{{ $columnIndex + 1 }}"
                                               data-max="{{ (float) $examSubject->total_marks }}"
                                               data-pass="{{ (float) $examSubject->effectivePassMark() }}"
                                               data-verified="{{ $cell->isVerified() ? 1 : 0 }}"
                                               data-locked="{{ ($locked || ! $editable) ? 1 : 0 }}"
                                               @readonly($locked || ! $editable)
                                               @class([
                                                   'input w-full py-2 text-center text-sm',
                                                   'input-error' => $errors->has('scores.' . $cell->id),
                                                   'bg-slate-50 text-slate-500' => $locked || ! $editable,
                                               ])
                                               placeholder="—">

                                        {{-- Live verdict, painted by the grid script. Kept below the
                                             box so a grade like "A1 100%" can never sit on top of
                                             the number being typed. --}}
                                        <span data-feedback
                                              class="mt-1 block text-center text-[11px] font-semibold"></span>

                                        @if ($locked)
                                            <p class="mt-0.5 text-center text-[11px] text-slate-400">
                                                Verified
                                            </p>
                                        @endif

                                        @error('scores.' . $cell->id)
                                            <p class="error-text text-center">{{ $message }}</p>
                                        @enderror
                                    @else
                                        <span class="block py-2 text-center text-xs text-slate-300">—</span>
                                    @endif
                                </td>
                            @endforeach

                            {{-- Painted by the grid script on every keystroke, so the officer
                                 sees the figure move as the marks go in. The starting values
                                 are rendered here, so they are right before any script runs. --}}
                            <td class="text-right align-top pt-3">
                                <span data-total class="font-semibold text-slate-900">
                                    {{ rtrim(rtrim(number_format($summary['total'], 2), '0'), '.') }}
                                </span>
                                <span data-count class="block text-[11px] text-slate-400">
                                    {{ $summary['marked'] === 1 ? '1 paper' : $summary['marked'] . ' papers' }}
                                </span>
                            </td>

                            <td class="text-right align-top pt-3">
                                {{-- Compared against the examination's cutoff, not a
                                     paper's pass mark: the average is what the cutoff
                                     desk measures against cutoff_mark. --}}
                                <span data-average @class([
                                    'font-semibold',
                                    'text-emerald-700' => $summary['marked'] > 0 && $summary['average'] >= (float) $exam->cutoff_mark,
                                    'text-amber-700' => $summary['marked'] > 0 && $summary['average'] < (float) $exam->cutoff_mark,
                                    'text-slate-400' => $summary['marked'] === 0,
                                ])>
                                    {{ rtrim(rtrim(number_format($summary['average'], 2), '0'), '.') }}%
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/70 px-5 py-4">
            <p class="max-w-2xl text-xs text-slate-500">
                @if ($mayVerify)
                    Marks you type are marked verified straight away, because you hold the verification
                    permission. Correcting one afterwards needs the override permission.
                @else
                    Marks you type wait to be verified by the exam officer before they are used for the cutoff.
                @endif
                Saving also records that these candidates have sat the paper.
            </p>

            <button type="submit" class="btn-primary btn-sm" @disabled(! $editable)>Save all marks</button>
        </div>
    </div>
</form>

@endif
