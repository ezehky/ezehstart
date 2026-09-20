@props(['service'])
<flux:select {{ $attributes->merge(['placeholder' => 'Select font', 'size' => 'sm']) }}>
    @foreach ($service->validItems(\App\Enums\EmailBlockItemEnum::FONT) as $key => $font)
        <flux:select.option value="{{ $key }}" label="{{ $font['label'] }}" />
    @endforeach
</flux:select>
