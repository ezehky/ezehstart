@props([
    'label',
    'resize' => true,
    'markdown' => true,
])
<flux:field>
    <flux:label class="justify-between">
        <span>{!! $label !!}</span>
        @if ($markdown)
            <flux:tooltip toggleable interactive position="top" align="center">
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="information-circle"
                    aria-label="Markdown formatting reference"
                />

                <flux:tooltip.content class="space-y-2 overflow-auto">
                    @foreach (kMarkdownFormat() as $item)
                        <div class="grid grid-cols-[auto_1fr] gap-x-3 text-sm">
                            <span class="font-bold">{{ $item[0] }}</span>
                            <code class="">{{ $item[1] }}</code>
                        </div>
                    @endforeach
                </flux:tooltip.content>
            </flux:tooltip>
        @endif
    </flux:label>

    <flux:textarea :resize="$resize ? 'vertical' : 'none'" {{ $attributes->merge(['rows' => 5]) }} />
    <flux:error name="{{ $attributes->get('wire:model') }}" />
</flux:field>
