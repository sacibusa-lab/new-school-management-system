@php
    // A plain @include, not a component: no `use` and no @props here. It is handed
    // `$group` (from SettingLayout) and `$previews`, and it draws one settings card.
    //
    // The card is shared because the settings are split across two pages — the
    // general one and the admissions one — and a field drawn twice is a field that
    // will eventually be drawn two different ways.
@endphp

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
