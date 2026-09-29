@extends('layouts.admin')

@section('title', 'Send a message')
@section('subtitle', 'Broadcast to applicants or to students who owe fees')

@section('actions')
    <a href="{{ route('admin.sms.index') }}" class="btn-secondary btn-sm">Back to the log</a>
@endsection

@section('content')

@if (! $enabled)
    <x-alert tone="warning" class="mb-6">
        Sending is switched <strong>off</strong> in Settings, so nothing can be broadcast right now.
    </x-alert>
@endif

@php
    // Sample values so the preview reads like a real sentence rather than
    // a wall of placeholders. Real values are substituted per recipient.
    $sample = [
        'school_name' => \App\Models\Setting::get('school_name', config('saci.school_name')),
        'guardian_name' => 'Mrs. Adeyemi',
        'full_name' => 'Chiamaka Adeyemi',
        'first_name' => 'Chiamaka',
        'surname' => 'Adeyemi',
        'registration_number' => 'SAC-00001',
        'admission_number' => 'SAC/2026/001',
        'class' => 'JSS 1',
        'session' => \App\Models\AcademicSession::current()?->name ?? '2025/2026',
        'average' => '72.5%',
        'cutoff' => '50%',
        'balance' => '₦0.00',
        'amount' => '₦50,000.00',
        'receipt_number' => 'RCP/2026/00001',
        'term' => 'First Term',
        'exam_date' => 'Sat, 14 Feb 2026',
        'exam_time' => '08:00',
        'venue' => 'Main Hall',
        'results_url' => route('public.results'),
    ];
@endphp

<form method="POST" action="{{ route('admin.sms.batch.store') }}">
    @csrf

    <div
        x-data="{
            audience: @js(old('audience', 'applicants_all')),
            templateKey: @js(old('template_key', $templates->first()->key ?? '')),
            useOverride: @js((bool) old('body_override')),
            overrideText: @js(old('body_override', '')),
            bodies: @js($templates->pluck('body', 'key')),
            sample: @js($sample),
            templatePlaceholders: @js($templatePlaceholders),
            audiencePlaceholders: @js($audiencePlaceholders),

            get source() {
                return this.useOverride ? this.overrideText : (this.bodies[this.templateKey] ?? '');
            },
            // Placeholders the chosen template needs that this audience cannot
            // fill. Without this, a payment receipt broadcast to a whole class
            // would print a literal {amount} at every parent.
            // NB: never use double quotes in here — this whole block lives inside
            // an HTML attribute and a double quote would end it early.
            get missing() {
                if (this.useOverride) {
                    return [];
                }

                const needed = this.templatePlaceholders[this.templateKey] ?? [];
                const available = this.audiencePlaceholders[this.audience] ?? [];

                return needed.filter((key) => ! available.includes(key));
            },
            get preview() {
                let text = this.source;

                for (const [key, value] of Object.entries(this.sample)) {
                    text = text.split('{' + key + '}').join(value);
                    text = text.split('{{ ' + key + ' }}').join(value);
                    text = text.split('{{' + key + '}}').join(value);
                }

                return text.trim() || 'Choose a message type above to see what will be sent.';
            },
            get length() { return this.source.trim().length; },
            get parts() { return Math.max(1, Math.ceil(this.length / 160)); },
        }"
        class="space-y-6">

        {{-- ================= Who ================= --}}
        <div class="card-pad">
            <h2 class="font-display text-lg font-semibold text-ink">1. Who is this going to?</h2>
            <p class="mt-1 text-sm text-muted">
                Only people with a usable mobile number are messaged. Anyone without one is skipped and reported.
            </p>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($audiences as $value => $label)
                    {{-- The highlight is Alpine-driven, not server-rendered: a stale
                         server-side class would stay lit after the choice changes. --}}
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-4 transition hover:border-line"
                           x-bind:class="audience === @js($value)
                               ? 'border-brand-600 bg-brand-50/60 ring-1 ring-brand-600'
                               : ''">
                        <input type="radio" name="audience" value="{{ $value }}" x-model="audience"
                               class="mt-0.5 h-4 w-4 border-line text-brand-600 focus:ring-brand-500">

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-ink">{{ $label }}</span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ number_format($audienceSizes[$value] ?? 0) }} on record
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2" x-show="audience === 'applicants_level'" x-cloak>
                <x-field name="level_id" label="Class applied for" type="select"
                         placeholder-option="Choose a class"
                         :value="old('level_id')"
                         :options="$levels->pluck('name', 'id')->all()" />
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2" x-show="audience === 'students_class'" x-cloak>
                <x-field name="school_class_id" label="Class" type="select"
                         placeholder-option="Choose a class"
                         :value="old('school_class_id')"
                         :options="$classes->mapWithKeys(fn ($c) => [$c->id => ($c->level?->name ? $c->level->name . ' · ' : '') . $c->name])->all()" />
            </div>
        </div>

        {{-- ================= What ================= --}}
        <div class="card-pad">
            <h2 class="font-display text-lg font-semibold text-ink">2. What should it say?</h2>
            <p class="mt-1 text-sm text-muted">
                Pick a saved message, or write your own. Placeholders in
                <code class="rounded bg-surface-3 px-1 py-0.5 text-xs">{curly braces}</code> are filled in per person.
            </p>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <x-field name="template_key" label="Message type" type="select"
                         placeholder-option="Choose a message type"
                         :value="old('template_key', $templates->first()->key ?? '')"
                         x-model="templateKey"
                         :options="$templates->pluck('name', 'key')->all()" />

                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <input type="checkbox" x-model="useOverride"
                               class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                        Write a different message instead
                    </label>
                </div>
            </div>

            <div class="mt-4" x-show="useOverride" x-cloak>
                <label for="body_override" class="label">Your message</label>
                <textarea id="body_override" name="body_override" rows="5" x-model="overrideText"
                          class="input font-mono text-[13px]"></textarea>
                <p class="mt-1 text-xs text-muted">
                    Keep it under 160 characters to stay inside a single SMS. Longer text is split and charged per part.
                </p>
            </div>

            {{-- Placeholder chips --}}
            <div class="mt-4">
                <p class="label">Available placeholders</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($placeholders as $placeholder)
                        <span class="rounded-md bg-surface-3 px-1.5 py-0.5 font-mono text-[11px] text-ink-soft">
                            {{ '{' . $placeholder . '}' }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ================= Preview ================= --}}
        <div class="card-pad">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-lg font-semibold text-ink">3. Check it before you send</h2>
                <p class="text-xs text-muted">
                    <span x-text="length"></span> characters ·
                    <span x-text="parts"></span> SMS part(s) per person
                </p>
            </div>

            {{-- A phone-shaped preview, because this is what the parent sees. --}}
            <div class="mt-4 max-w-md rounded-2xl bg-slate-900 p-4">
                <p class="mb-2 text-[11px] uppercase tracking-wider text-muted">Message preview</p>
                <div class="rounded-xl bg-surface p-3">
                    <p class="whitespace-pre-line text-sm leading-relaxed text-ink-soft" x-text="preview"></p>
                </div>
            </div>

            <p class="mt-3 text-xs text-muted">
                The names, numbers and amounts above are samples so you can read the sentence flow.
                Real values are substituted for each recipient.
            </p>
        </div>

        {{-- ================= Send ================= --}}
        <div class="card-pad">
            {{-- Blocked before it becomes a phone call from a confused parent. --}}
            <div x-show="missing.length > 0" x-cloak
                 class="mb-5 rounded-xl bg-rose-50 dark:bg-rose-950/40 p-4 text-sm text-rose-900 dark:text-rose-100 ring-1 ring-inset ring-rose-600/15">
                <p class="font-semibold">This message needs details this audience does not have</p>
                <p class="mt-1">
                    It uses
                    <template x-for="key in missing" :key="key">
                        <span class="font-mono font-semibold" x-text="'{' + key + '}'"></span>
                    </template>
                    — filled in from a real payment or result, so it cannot be broadcast as it stands.
                    Tick <strong>Write a different message instead</strong> and take that part out.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2 text-sm text-ink-soft">
                    <input type="checkbox" name="send_now" value="1" checked
                           class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                    Send immediately <span class="text-muted">(untick to leave them in the outbox)</span>
                </label>

                <div class="ml-auto flex items-center gap-3">
                    <a href="{{ route('admin.sms.index') }}" class="btn-ghost btn-sm">Cancel</a>
                    <button type="submit" class="btn-primary btn-sm"
                            @disabled(! $enabled) x-bind:disabled="missing.length > 0">
                        Queue messages
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

@endsection
