<flux:radio.group {{ $attributes->merge(['size' => 'sm']) }} label="Border Radius" variant="segmented">
    @foreach (\App\Enums\EmailBlockItemEnum::validRadius() as $key => $item)
        <flux:radio value="{{ $key }}" icon="{{ $item['icon'] }}" title="{{ $item['label'] }}" />
    @endforeach
</flux:radio.group>
