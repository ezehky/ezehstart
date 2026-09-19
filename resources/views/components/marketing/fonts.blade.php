<flux:select {{ $attributes->merge(['placeholder' => 'Select font', 'size' => 'sm']) }}>
    @foreach (\App\Enums\EmailBlockItemEnum::fonts() as $key => $font)
        <flux:select.option value="{{ $key }}" label="{{ $font['label'] }}" />
    @endforeach
</flux:select>
