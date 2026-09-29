@extends('layouts.admin')

@section('title', 'Settings')
@section('subtitle', 'Academic session, branding, numbering, admissions, letters, messaging, fees and results')

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

            @if ($currentTerm && ($currentTerm->starts_on || $currentTerm->ends_on))
                <p class="mt-0.5 text-xs text-muted">
                    {{ $currentTerm->starts_on?->format('j M Y') ?? '—' }}
                    to
                    {{ $currentTerm->ends_on?->format('j M Y') ?? '—' }}
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
                    {{-- Grouped by session rather than swapped by script: one save sets
                         both, and the pairing is visible instead of implied. --}}
                    <select id="term_id" name="term_id"
                            class="input @error('term_id') input-error @enderror">
                        @foreach ($sessions as $session)
                            <optgroup label="{{ $session->name }}">
                                @forelse ($session->terms as $term)
                                    <option value="{{ $term->id }}"
                                            @selected((int) old('term_id', $currentTerm?->id) === $term->id)>
                                        {{ $term->name }}
                                    </option>
                                @empty
                                    <option value="" disabled>No terms yet — they will be created</option>
                                @endforelse
                            </optgroup>
                        @endforeach
                    </select>

                    @error('term_id')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <p class="hint mt-3">
                A session with no terms has First, Second and Third Term laid down for it.
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
                {{ $sessions->sum(fn ($s) => $s->terms->count()) }} term(s) across {{ $sessions->count() }} session(s)
            </span>
        </div>

        <div class="mt-5 space-y-4">
            @foreach ($sessions as $session)
                @php
                    // Counted up front so a delete button is never offered for
                    // something the server is going to refuse.
                    $blockers = $academic->blockers($session);
                @endphp

                <div class="rounded-2xl border border-line">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line-soft p-4">
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
                                  onsubmit="return confirm('Delete {{ $session->name }} and its {{ $session->terms->count() }} term(s)?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Delete session</button>
                            </form>
                        @endif
                    </div>

                    <ul class="divide-y divide-line-soft">
                        @forelse ($session->terms as $term)
                            @php $termBlockers = $academic->termBlockers($term); @endphp

                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 text-sm text-ink-soft">
                                        {{ $term->name }}

                                        @if ($term->is_current)
                                            <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Active</span>
                                        @endif
                                    </p>

                                    <p class="mt-0.5 text-xs text-muted">
                                        @if ($term->starts_on || $term->ends_on)
                                            {{ $term->starts_on?->format('j M Y') ?? '—' }}
                                            to
                                            {{ $term->ends_on?->format('j M Y') ?? '—' }}
                                        @else
                                            No dates set
                                        @endif
                                    </p>
                                </div>

                                @if ($term->is_current)
                                    <p class="text-xs text-muted">The active term cannot be deleted</p>
                                @elseif ($termBlockers !== [])
                                    <p class="text-xs text-muted">Holds {{ $academic->describe($termBlockers) }}</p>
                                @else
                                    <form method="POST"
                                          action="{{ route('admin.settings.academic.terms.destroy', $term) }}"
                                          onsubmit="return confirm('Delete {{ $term->name }} from {{ $session->name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Delete</button>
                                    </form>
                                @endif
                            </li>
                        @empty
                            <li class="px-4 py-3 text-xs text-muted">
                                No terms yet — add one below, or set this session as current and the
                                standard three will be created for it.
                            </li>
                        @endforelse

                        {{-- Add a term, in place, under the terms it joins. --}}
                        <li class="bg-surface-2 px-4 py-3">
                            <form method="POST" action="{{ route('admin.settings.academic.terms.store') }}"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf
                                <input type="hidden" name="academic_session_id" value="{{ $session->id }}">

                                <div class="min-w-40 flex-1">
                                    <label for="term_name_{{ $session->id }}" class="sr-only">Term name</label>
                                    <input id="term_name_{{ $session->id }}" name="name" type="text" required
                                           placeholder="Term name, e.g. First Term" class="input py-2 text-sm">
                                </div>

                                <div>
                                    <label for="term_starts_{{ $session->id }}" class="sr-only">Starts</label>
                                    <input id="term_starts_{{ $session->id }}" name="starts_on" type="date"
                                           class="input py-2 text-sm" title="Starts on">
                                </div>

                                <div>
                                    <label for="term_ends_{{ $session->id }}" class="sr-only">Ends</label>
                                    <input id="term_ends_{{ $session->id }}" name="ends_on" type="date"
                                           class="input py-2 text-sm" title="Ends on">
                                </div>

                                <button type="submit" class="btn-secondary btn-sm">Add term</button>
                            </form>
                        </li>
                    </ul>
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
                It is added without becoming the session the school is in — moving the school is a
                separate step, so a session can be set up before it starts.
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

                <label class="flex items-center gap-2 pb-2 text-xs text-ink-soft">
                    <input type="checkbox" name="with_terms" value="1" checked
                           class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                    Create First, Second and Third Term for it
                </label>

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
                 Address above its own Name and scattered the groups. --}}
            @foreach ($groups as $group)
                <div class="card-pad">
                    <h2 class="text-base font-semibold text-ink">
                        {{ $group['label'] }}
                    </h2>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        @foreach ($group['items'] as $setting)
                            @php
                                // An image needs the room for its preview and its picker;
                                // a sentence looks wrong squeezed into half a row.
                                $spansTwo = \App\Support\SettingLayout::isWide($setting->key)
                                    || in_array($setting->type, ['text', 'image'], true);
                            @endphp

                            <div @class(['sm:col-span-2' => $spansTwo])>
                                @if ($setting->type === 'bool')
                                    <label class="flex items-start gap-3 rounded-xl border border-line p-4">
                                        <input type="hidden" name="settings[{{ $setting->key }}][value]" value="">
                                        <input type="checkbox"
                                               name="settings[{{ $setting->key }}][value]"
                                               value="1"
                                               @checked((bool) $setting->value)
                                               class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                                        <span>
                                            <span class="block text-sm font-medium text-ink-soft">
                                                {{ $setting->label ?? $setting->key }}
                                            </span>
                                            <span class="mt-0.5 block text-xs text-muted">
                                                @if ($setting->key === 'registration_open')
                                                    Turn off to close the public application form.
                                                @else
                                                    Tick to enable.
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                @elseif ($setting->type === 'image')
                                    @include('admin.settings.partials.image-field', ['setting' => $setting])
                                @else
                                    @php
                                        $displayValue = $setting->value;

                                        if ($setting->type === 'json') {
                                            $decoded = json_decode((string) $setting->value, true);
                                            $displayValue = is_array($decoded) ? implode(', ', $decoded) : $setting->value;
                                        }

                                        // Nested field names need dot notation for old().
                                        $oldKey = 'settings.' . $setting->key . '.value';
                                        $inputName = 'settings[' . $setting->key . '][value]';
                                        $inputId = 'setting_' . $setting->key;
                                    @endphp

                                    <label for="{{ $inputId }}" class="label">
                                        {{ $setting->label ?? $setting->key }}
                                    </label>

                                    @if ($setting->type === 'text')
                                        <textarea id="{{ $inputId }}"
                                                  name="{{ $inputName }}"
                                                  rows="3"
                                                  class="input @error($oldKey) input-error @enderror">{{ old($oldKey, $displayValue) }}</textarea>
                                    @else
                                        <input id="{{ $inputId }}"
                                               type="{{ $setting->type === 'int' ? 'number' : 'text' }}"
                                               name="{{ $inputName }}"
                                               value="{{ old($oldKey, $displayValue) }}"
                                               class="input @error($oldKey) input-error @enderror">
                                    @endif

                                    @error($oldKey)
                                        <p class="error-text">{{ $message }}</p>
                                    @enderror

                                    @php
                                        $hint = match ($setting->key) {
                                            'entrance_exam_subjects' => 'Subject codes, comma separated (MTH, ENG, GPR). These are pre-ticked on the examination form.',
                                            'admission_number_prefix' => 'Registration numbers look like ' . $previews['admission'] . '.',
                                            'student_number_prefix' => 'Admission numbers look like ' . $previews['student'] . '.',
                                            'invoice_prefix' => 'Invoices look like ' . $previews['invoice'] . '.',
                                            'receipt_prefix' => 'Receipts look like ' . $previews['receipt'] . '.',
                                            'currency_symbol' => 'Shown before every amount.',
                                            'ca_max_total' => 'Continuous assessment marks out of this total.',
                                            'exam_max_total' => 'Examination marks out of this total.',
                                            default => null,
                                        };
                                    @endphp

                                    @if ($hint)
                                        <p class="hint">{{ $hint }}</p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
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
