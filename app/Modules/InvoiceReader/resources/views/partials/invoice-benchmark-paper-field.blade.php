@php
    $path = 'correction.'.$field;
    $fieldClass = \Illuminate\Support\Arr::toCssClasses([
        'block w-full min-w-0 rounded-sm border-0 bg-transparent px-1 py-0.5 text-inherit outline-none placeholder:text-slate-400 hover:bg-slate-100 focus:bg-white focus:ring-1 focus:ring-primary-600',
        $class ?? '',
        'ring-1 ring-red-600' => $errors->has($path),
    ]);
@endphp

@if (isset($options))
    <select
        wire:model="{{ $path }}"
        aria-label="{{ $label }}"
        @if ($errors->has($path)) aria-invalid="true" @endif
        class="{{ $fieldClass }}"
    >
        <option value="">{{ __('Not specified') }}</option>
        @if (filled($currentValue) && ! array_key_exists($currentValue, $options))
            <option value="{{ $currentValue }}">{{ $currentValue }}</option>
        @endif
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
    </select>
@else
    <input
        type="text"
        wire:model="{{ $path }}"
        aria-label="{{ $label }}"
        @if ($errors->has($path)) aria-invalid="true" @endif
        maxlength="500"
        placeholder="—"
        inputmode="{{ ($decimal ?? false) ? 'decimal' : 'text' }}"
        class="{{ $fieldClass }}"
    >
@endif
@error($path)
    <span class="block text-xs text-red-700">{{ $message }}</span>
@enderror
