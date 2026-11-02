@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null])
<div class="mb-5">
    <label for="f-{{ $name }}" class="label">{{ $label }}</label>
    <input id="f-{{ $name }}" type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : old($name, $value) }}"
        {{ $attributes->class(['input', 'ring-red-400 focus:ring-red-500' => $errors->has($name)]) }}>
    @error($name)<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@else @if ($help)<p class="help">{{ $help }}</p>@endif @enderror
</div>
