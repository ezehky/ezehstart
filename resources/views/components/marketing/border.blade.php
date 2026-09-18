@props(['prefix', 'element' => 'border'])
<flux:field>
    <flux:label>Border</flux:label>
    <flux:input.group>
        <flux:select class="w-24" wire:model.live="{{ $prefix }}.{{ $element }}.width" size="sm">
            @for ($i = 0; $i <= 5; $i++)
                <flux:select.option value="{{ $i }}">{{ $i }}px</flux:select.option>
            @endfor
        </flux:select>
        <x-form.color-field class="w-full" wire:model.live="{{ $prefix }}.{{ $element }}.color" size="sm" />
    </flux:input.group>
</flux:field>
