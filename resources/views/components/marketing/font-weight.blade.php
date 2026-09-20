@props(['prefix', 'service'])
<flux:radio.group wire:model.live="{{ $prefix }}.font_weight" label="Font Weight" variant="segmented" size="sm">
    @foreach ($service->validItems(\App\Enums\EmailBlockItemEnum::FONT_WEIGHT) as $key => $item)
        <flux:radio value="{{ $key }}">
            <x-slot:icon>
                <flux:icon name="case-sensitive" style="{{ $item['icon_style'] }}" title="{{ $key }}" />
            </x-slot:icon>
        </flux:radio>
    @endforeach
</flux:radio.group>
