@extends('layouts.admin')

@section('title', 'Settings')
@section('subtitle', 'Academic session, branding, numbering, messaging, fees and results')

@section('content')

{{-- ================= Where the school is now =================
     Its own form, above the settings form rather than inside it: HTML forms
     cannot nest, and this control moves rows in academic_sessions and terms
     rather than writing setting values. --}}
<div class="card-pad">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-ink">Academic session</h2>
            <p class="mt-1 max-w-2xl text-sm text-muted">
                The session the school is in and the term that is active. Every figure the
                school reports hangs off these two answers — an invoice, a result, an
                attendance register — so only one of each can be current at a time.
            </p>
        </div>

        {{-- The state as it stands, so the answer is readable without opening a
             select. The term dates come with it because "Second Term" means
             nothing on its own in April. --}}
        <div class="rounded-xl bg-surface-2 px-4 py-3 text-right ring-1 ring-line">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted">
                Currently
            </p>
            <p class="mt-0.5 text-sm font-semibold text-ink">
                {{ $currentSession?->name ?? 'No session set' }}
                @if ($currentTerm)
                    · {{ $currentTerm->name }}
                @endif
            </p>

            @php
                // A term has no dates of its own; the dates belong to the pair of
                // term and session, so this asks for the current session's.
                $currentDates = $currentTerm?->datesIn($currentSession);
            @endphp

            @if ($currentDates && $currentDates->describe())
                <p class="mt-0.5 text-xs text-muted">
                    {{ $currentDates->describe() }}
                </p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.academic.update') }}" class="mt-5">
        @csrf
        @method('PUT')

        {{-- Fields, their note, then the action - one narrow column, left aligned.
             Lined up across three columns of a full-width row, the button was
             aligned to the bottom of the tallest cell rather than to the fields: on
             a wide screen it sat in the far corner of the card, level with the note,
             and looked like it belonged to the note. An error under a select moved
             it again. Stacked, nothing can drift. --}}
        <div class="lg:max-w-2xl">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="academic_session_id" class="label">Academic session</label>
                    <select id="academic_session_id" name="academic_session_id"
                            class="input @error('academic_session_id') input-error @enderror">
                        @foreach ($sessions as $session)
                            <option value="{{ $session->id }}"
                                    @selected((int) old('academic_session_id', $currentSession?->id) === $session->id)>
                                {{ $session->name }}
                            </option>
                        @endforeach
                    </select>

                    @error('academic_session_id')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="term_id" class="label">Active term</label>
                    {{-- One flat list, not one grouped by session: a term belongs to
                         every session, so there is nothing to group it by. --}}
                    <select id="term_id" name="term_id"
                            class="input @error('term_id') input-error @enderror">
                        @forelse ($terms as $term)
                            <option value="{{ $term->id }}"
                                    @selected((int) old('term_id', $currentTerm?->id) === $term->id)>
                                {{ $term->name }}
                            </option>
                        @empty
                            <option value="" disabled>No terms yet — add them below</option>
                        @endforelse
                    </select>

                    @error('term_id')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <p class="hint mt-3">
                The active term is the term the school is in, for the session it is in.
            </p>

            <button type="submit" class="btn-primary mt-5">Set session and term</button>
        </div>
    </form>

    {{-- ================= The calendar itself =================
         One card for the whole job: which session and term the school is in, and
         the sessions and terms that exist to choose between. --}}
    <div class="mt-8 border-t border-line pt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-ink">Sessions and terms</h3>
                <p class="mt-1 max-w-2xl text-xs text-muted">
                    A session and its terms can be deleted only while nothing is written against
                    them. Applicants, students, examinations, invoices and results all disappear
                    with the session they belong to, so the office is told what is in the way
                    rather than finding out afterwards.
                </p>
            </div>

            <span class="badge-neutral">
                {{ $sessions->count() }} session(s) · {{ $terms->count() }} term(s), the same in every session
            </span>
        </div>

        {{-- ================= Sessions =================
             A year. Its terms are not listed under it, because they are not its. --}}
        <div class="mt-5 space-y-3">
            @foreach ($sessions as $session)
                @php
                    // Counted up front so a delete button is never offered for
                    // something the server is going to refuse.
                    $blockers = $academic->blockers($session);
                @endphp

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line p-4">
                    <div>
                        <p class="flex items-center gap-2 font-display text-sm font-semibold text-ink">
                            {{ $session->name }}

                            @if ($session->is_current)
                                <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Current</span>
                            @endif
                        </p>

                        <p class="mt-0.5 text-xs text-muted">
                            @if ($session->starts_on || $session->ends_on)
                                {{ $session->starts_on?->format('j M Y') ?? '—' }}
                                to
                                {{ $session->ends_on?->format('j M Y') ?? '—' }}
                            @else
                                No dates set
                            @endif
                        </p>

                        @if ($blockers !== [])
                            <p class="mt-1 text-xs text-muted">
                                Holds {{ $academic->describe($blockers) }}
                            </p>
                        @endif
                    </div>

                    @if ($session->is_current)
                        <p class="text-xs text-muted">The session the school is in cannot be deleted</p>
                    @elseif ($blockers !== [])
                        <p class="text-xs text-muted">Has records against it, so it cannot be deleted</p>
                    @else
                        <form method="POST"
                              action="{{ route('admin.settings.academic.sessions.destroy', $session) }}"
                              onsubmit="return confirm('Delete {{ $session->name }}? Its terms are shared, so they stay.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Delete session</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Add a session. The name is suggested from the last one, so the common
             case is one click and no arithmetic. --}}
        <form method="POST" action="{{ route('admin.settings.academic.sessions.store') }}"
              class="mt-4 rounded-2xl border border-dashed border-line bg-surface-2 p-4">
            @csrf

            <p class="text-sm font-semibold text-ink">Add an academic session</p>
            <p class="mt-1 text-xs text-muted">
                It arrives with every term already in it — terms are not created per session — and it
                is not made current. Moving the school is a separate step, so a session can be set up
                before it starts.
            </p>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-36">
                    <label for="new_session_name" class="label">Session</label>
                    <input id="new_session_name" name="name" type="text" required
                           value="{{ old('name', $suggestedSession) }}"
                           placeholder="2027/2028" class="input @error('name') input-error @enderror">
                </div>

                <div>
                    <label for="new_session_starts" class="label">Starts</label>
                    <input id="new_session_starts" name="starts_on" type="date"
                           value="{{ old('starts_on') }}" class="input @error('starts_on') input-error @enderror">
                </div>

                <div>
                    <label for="new_session_ends" class="label">Ends</label>
                    <input id="new_session_ends" name="ends_on" type="date"
                           value="{{ old('ends_on') }}" class="input @error('ends_on') input-error @enderror">
                </div>

                <button type="submit" class="btn-primary btn-sm">Add session</button>
            </div>

            @error('name')
                <p class="error-text">{{ $message }}</p>
            @enderror
            @error('starts_on')
                <p class="error-text">{{ $message }}</p>
            @enderror
            @error('ends_on')
                <p class="error-text">{{ $message }}</p>
            @enderror
        </form>

        {{-- ================= Terms =================
             One set for the school, not one set per session. The dates shown are for
             the session the school is in; each term keeps its own dates in each
             session, so setting next year's does not touch last year's. --}}
        <div class="mt-8 rounded-2xl border border-line">
            <div class="border-b border-line-soft p-4">
                <p class="text-sm font-semibold text-ink">Terms</p>
                <p class="mt-1 max-w-2xl text-xs text-muted">
                    The same terms run in every session — First Term is First Term in 2026/2027 and in
                    2027/2028. A term is added once here and belongs to every session from then on,
                    including sessions added later. Results are stored against the term
                    <em>and</em> the session, so moving the school on leaves every earlier session
                    reachable.
                </p>
            </div>

            <ul class="divide-y divide-line-soft">
                @foreach ($terms as $term)
                    @php
                        $termBlockers = $academic->termBlockers($term);
                        $datesHere = $term->datesIn($currentSession);
                    @endphp

                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-sm text-ink-soft">
                                    {{ $term->name }}

                                    @if ($term->is_current)
                                        <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Active</span>
                                    @endif
                                </p>

                                <p class="mt-0.5 text-xs text-muted">
                                    {{ $currentSession?->name ?? 'No current session' }}:
                                    {{ $datesHere?->describe() ?? 'no dates set' }}
                                </p>
                            </div>

                            @if ($term->is_current)
                                <p class="text-xs text-muted">The active term cannot be deleted</p>
                            @elseif ($termBlockers !== [])
                                <p class="text-xs text-muted">Holds {{ $academic->describe($termBlockers) }}</p>
                            @else
                                <form method="POST"
                                      action="{{ route('admin.settings.academic.terms.destroy', $term) }}"
                                      onsubmit="return confirm('Delete {{ $term->name }}? It is removed from every session.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Delete</button>
                                </form>
                            @endif
                        </div>

                        {{-- Dates are the one thing that IS per session, so they are
                             edited against a named session rather than the term. --}}
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs font-medium text-brand-700 hover:text-brand-900 dark:text-brand-200">
                                Set dates for a session
                            </summary>

                            <form method="POST" action="{{ route('admin.settings.academic.terms.dates', $term) }}"
                                  class="mt-3 flex flex-wrap items-end gap-3">
                                @csrf
                                @method('PUT')

                                <div class="min-w-40">
                                    <label for="dates_session_{{ $term->id }}" class="label">Session</label>
                                    <select id="dates_session_{{ $term->id }}" name="academic_session_id" class="input py-2 text-sm">
                                        @foreach ($sessions as $session)
                                            <option value="{{ $session->id }}" @selected($session->id === $currentSession?->id)>
                                                {{ $session->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="dates_starts_{{ $term->id }}" class="label">Starts</label>
                                    <input id="dates_starts_{{ $term->id }}" name="starts_on" type="date"
                                           value="{{ $datesHere?->starts_on?->format('Y-m-d') }}" class="input py-2 text-sm">
                                </div>

                                <div>
                                    <label for="dates_ends_{{ $term->id }}" class="label">Ends</label>
                                    <input id="dates_ends_{{ $term->id }}" name="ends_on" type="date"
                                           value="{{ $datesHere?->ends_on?->format('Y-m-d') }}" class="input py-2 text-sm">
                                </div>

                                <button type="submit" class="btn-secondary btn-sm">Save dates</button>
                            </form>
                        </details>
                    </li>
                @endforeach
            </ul>

            {{-- Add a term. It joins every session at once. --}}
            <form method="POST" action="{{ route('admin.settings.academic.terms.store') }}"
                  class="flex flex-wrap items-end gap-3 border-t border-line-soft bg-surface-2 p-4">
                @csrf

                <div class="min-w-44 flex-1">
                    <label for="new_term_name" class="label">Add a term</label>
                    <input id="new_term_name" name="name" type="text" required
                           value="{{ old('name') }}"
                           placeholder="Term name, e.g. Fourth Term"
                           class="input @error('name') input-error @enderror">
                </div>

                <div class="min-w-36">
                    <label for="new_term_session" class="label">Dates in</label>
                    <select id="new_term_session" name="dates_in" class="input">
                        @foreach ($sessions as $session)
                            <option value="{{ $session->id }}" @selected($session->id === $currentSession?->id)>
                                {{ $session->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="new_term_starts" class="label">Starts</label>
                    <input id="new_term_starts" name="starts_on" type="date"
                           value="{{ old('starts_on') }}" class="input @error('starts_on') input-error @enderror">
                </div>

                <div>
                    <label for="new_term_ends" class="label">Ends</label>
                    <input id="new_term_ends" name="ends_on" type="date"
                           value="{{ old('ends_on') }}" class="input @error('ends_on') input-error @enderror">
                </div>

                <button type="submit" class="btn-primary btn-sm">Add term</button>

                <p class="hint mt-0 w-full">
                    The dates are optional and belong to the session chosen — a term's dates are its
                    own in each session.
                </p>
            </form>

            @error('name')
                <p class="error-text px-4 pb-4">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>

<form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="mt-6">
    @csrf
    @method('PUT')

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ================= Settings groups ================= --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- The order comes from SettingLayout, not from the query: ordering by
                 group then key is alphabetical twice over, which put the school's
                 Address above its own Name and scattered the groups. The card itself
                 is drawn by a partial, because the admissions groups are drawn the
                 same way on their own page. --}}
            @foreach ($groups as $group)
                @include('admin.settings.partials.group', ['group' => $group])
            @endforeach

            <div class="flex items-center gap-3">
                <button type="submit" class="btn-primary btn-lg">Save settings</button>
                <p class="text-xs text-muted">Changes take effect immediately.</p>
            </div>
        </div>

        {{-- ================= Numbering preview ================= --}}
        <aside class="space-y-6">
            <div class="card-pad">
                <h3 class="text-sm font-semibold text-ink">Next numbers to be issued</h3>

                <dl class="mt-4 space-y-3.5 text-sm">
                    @foreach ([
                        ['Registration number', $previews['admission']],
                        ['Admission number', $previews['student']],
                        ['Invoice', $previews['invoice']],
                        ['Receipt', $previews['receipt']],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between gap-3 border-b border-line-soft pb-3 last:border-0 last:pb-0">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="font-mono text-xs font-semibold text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 text-xs text-muted">
                    Registration numbers ascend forever. Admission, invoice and receipt
                    numbers restart each academic year.
                </p>
            </div>
        </aside>
    </div>
</form>

{{-- ================= Number series ================= --}}
<div class="mt-6 card-pad">
    <h2 class="text-base font-semibold text-ink">Move a number series forward</h2>
    <p class="mt-1 text-sm text-muted">
        Only needed when migrating from an older system — for example, so the next
        registration number continues where your previous records stopped.
    </p>

    <form method="POST" action="{{ route('admin.settings.sequences.update') }}" class="mt-5 grid gap-4 sm:grid-cols-4">
        @csrf

        <x-field name="type" label="Series" type="select" required
                 :options="[
                     'admission_registration' => 'Admission registration (SAC-00001)',
                     'student_number' => 'Admission number (SAC/2026/001)',
                     'invoice' => 'Invoice number',
                     'receipt' => 'Receipt number',
                 ]" />

        <x-field name="scope" label="Year / scope" required
                 :value="(string) now()->year"
                 hint="Use 'global' for registration numbers." />

        <x-field name="last_number" label="Last number already used" type="number" required min="0"
                 hint="The next issue will be this + 1." />

        <div class="flex items-end">
            <button type="submit" class="btn-secondary w-full">Update series</button>
        </div>
    </form>
</div>
@endsection
