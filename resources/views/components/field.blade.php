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
                <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>
                    {{ $optionLabel }}
                </option>
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
