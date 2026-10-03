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
                // a sentence or a key looks wrong squeezed into half a row.
                $spansTwo = \App\Support\SettingLayout::isWide($setting->key)
                    || in_array($setting->type, ['text', 'image', 'secret'], true);
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
                @elseif ($setting->type === 'secret')
                    @php
                        $displayValue = $setting->value;

                        // Nested field names need dot notation for old().
                        $oldKey = 'settings.' . $setting->key . '.value';
                        $inputName = 'settings[' . $setting->key . '][value]';
                        $inputId = 'setting_' . $setting->key;
                    @endphp

                    {{--
                        A key, kept as dots until it is asked for.

                        The real value is in the page, because it has to be: the
                        office can only check that the key in the provider's
                        dashboard is the one pasted here by looking at it. Alpine
                        swaps the type of the one input — nothing is fetched, and
                        nothing is kept from anyone who can already open the page.
                    --}}
                    <div x-data="{ shown: false }">
                        <label for="{{ $inputId }}" class="label">
                            {{ $setting->label ?? $setting->key }}
                        </label>

                        <div class="relative">
                            <input :type="shown ? 'text' : 'password'"
                                   type="password"
                                   id="{{ $inputId }}"
                                   name="{{ $inputName }}"
                                   value="{{ old($oldKey, $displayValue) }}"
                                   autocomplete="new-password"
                                   spellcheck="false"
                                   class="input pr-11 @error($oldKey) input-error @enderror">

                            <button type="button"
                                    @click="shown = ! shown"
                                    :title="shown ? 'Hide' : 'Show'"
                                    :aria-label="shown ? 'Hide' : 'Show'"
                                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted hover:text-ink">
                                <x-nav-icon name="eye" class="h-4 w-4" x-show="! shown" />
                                <x-nav-icon name="eye-off" class="h-4 w-4" x-show="shown" x-cloak />
                            </button>
                        </div>

                        @error($oldKey)
                            <p class="error-text">{{ $message }}</p>
                        @enderror

                        @php
                            $hint = match ($setting->key) {
                                'paystack_secret_key' => 'From the Paystack dashboard, under Settings → API Keys & Webhooks. This is the one that is never shown to a parent.',
                                'termii_api_key' => 'The key Termii issued to the school. Text messages stop going out if this is wrong.',
                                'ai_api_key' => 'The key the AI reader signs its requests with. Without it, a sheet has to be typed in by hand.',
                                default => null,
                            };
                        @endphp

                        @if ($hint)
                            <p class="hint">{{ $hint }}</p>
                        @endif
                    </div>
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
                            'paystack_public_key' => 'Safe to publish — it is the key the payment page opens with.',
                            'termii_sender_id' => 'The name a parent sees the message come from. Eleven characters at most, and Termii has to have approved it.',
                            'termii_channel' => 'generic, dnd or whatsapp. Leave it as generic unless Termii has told you otherwise.',
                            'ai_provider' => 'gemini, openai or deepseek — whichever the school holds a key with. Anything else is read as AI marking being switched off.',
                            'ai_model' => 'Leave empty for the provider\'s default. It has to be a model that can READ a photograph of a sheet; a text-only one will refuse the upload.',
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
