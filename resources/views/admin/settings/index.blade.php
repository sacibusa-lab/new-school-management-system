@extends('layouts.admin')

@section('title', 'Settings')
@section('subtitle', 'Branding, numbering, admissions, letters, messaging, fees and results')

@section('content')
<form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ================= Settings groups ================= --}}
        <div class="space-y-6 lg:col-span-2">
            @foreach ($settings as $group => $items)
                <div class="card-pad">
                    <h2 class="text-base font-semibold text-slate-900">
                        {{ $groups[$group] ?? ucfirst($group) }}
                    </h2>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        @foreach ($items as $setting)
                            @php
                                $isWide = in_array($setting->key, ['contact_address', 'admission_letter_note'], true);
                                // An image needs the room for its preview and its picker.
                                $spansTwo = $isWide || in_array($setting->type, ['text', 'image'], true);
                            @endphp

                            <div @class(['sm:col-span-2' => $spansTwo])>
                                @if ($setting->type === 'bool')
                                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                                        <input type="hidden" name="settings[{{ $setting->key }}][value]" value="">
                                        <input type="checkbox"
                                               name="settings[{{ $setting->key }}][value]"
                                               value="1"
                                               @checked((bool) $setting->value)
                                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-700">
                                                {{ $setting->label ?? $setting->key }}
                                            </span>
                                            <span class="mt-0.5 block text-xs text-slate-500">
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
                <p class="text-xs text-slate-500">Changes take effect immediately.</p>
            </div>
        </div>

        {{-- ================= Numbering preview ================= --}}
        <aside class="space-y-6">
            <div class="card-pad">
                <h3 class="text-sm font-semibold text-slate-900">Next numbers to be issued</h3>

                <dl class="mt-4 space-y-3.5 text-sm">
                    @foreach ([
                        ['Registration number', $previews['admission']],
                        ['Admission number', $previews['student']],
                        ['Invoice', $previews['invoice']],
                        ['Receipt', $previews['receipt']],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="font-mono text-xs font-semibold text-slate-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 text-xs text-slate-500">
                    Registration numbers ascend forever. Admission, invoice and receipt
                    numbers restart each academic year.
                </p>
            </div>
        </aside>
    </div>
</form>

{{-- ================= Number series ================= --}}
<div class="mt-6 card-pad">
    <h2 class="text-base font-semibold text-slate-900">Move a number series forward</h2>
    <p class="mt-1 text-sm text-slate-500">
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
