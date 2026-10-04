@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'hint' => null,
    'placeholder' => null,
    'options' => null,
    'placeholderOption' => 'Select an option',
])

@php
    $id = $attributes->get('id') ?? $name;
    $hasError = $errors->has($name);
    $inputClasses = 'input' . ($hasError ? ' input-error' : '');
@endphp

<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $id }}" class="label">
            {{ $label }}
            @if ($required)<span class="text-rose-500">*</span>@endif
        </label>
    @endif

    @if ($type === 'textarea')
        <textarea id="{{ $id }}"
                  name="{{ $name }}"
                  @if ($required) required @endif
                  @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                  {{ $attributes->except(['class', 'id'])->merge(['class' => $inputClasses, 'rows' => 4]) }}>{{ old($name, $value) }}</textarea>

    @elseif ($type === 'select')
        <select id="{{ $id }}"
                name="{{ $name }}"
                @if ($required) required @endif
                {{ $attributes->except(['class', 'id'])->merge(['class' => $inputClasses]) }}>
            @if ($placeholderOption !== false)
                <option value="">{{ $placeholderOption }}</option>
            @endif

            @foreach ($options ?? [] as $optionValue => $optionLabel)
                @if (is_array($optionLabel))
                    {{--
                        A section of the list rather than an answer: the classes of one
                        year group, drawn under the year group's name. The array key is
                        the heading, so a caller hands over
                        ['JSS1' => ['3:0' => 'All of JSS1', '3:5' => 'A']].

                        Nested like this rather than as a flat list with the year group
                        spelled into every label, because the year group is a heading and
                        repeating it on six lines makes six things to read where there is
                        one. A browser cannot search inside an optgroup, which costs
                        nothing here: the school has six year groups.
                    --}}
                    <optgroup label="{{ $optionValue }}">
                        @foreach ($optionLabel as $groupedValue => $groupedLabel)
                            <option value="{{ $groupedValue }}" @selected((string) old($name, $value) === (string) $groupedValue)>
                                {{ $groupedLabel }}
                            </option>
                        @endforeach
                    </optgroup>
                @else
                    <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>
                        {{ $optionLabel }}
                    </option>
                @endif
            @endforeach
        </select>

    @else
        <input id="{{ $id }}"
               type="{{ $type }}"
               name="{{ $name }}"
               value="{{ old($name, $value) }}"
               @if ($required) required @endif
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif
               {{ $attributes->except(['class', 'id'])->merge(['class' => $inputClasses]) }}>
    @endif

    @if ($hint && ! $hasError)
        <p class="hint">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="error-text">{{ $message }}</p>
    @enderror
</div>
