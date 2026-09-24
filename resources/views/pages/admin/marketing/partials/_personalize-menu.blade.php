{{--
    The "+ Personalize" control on a text field.

        @include('pages.admin.marketing.partials._personalize-menu', ['block' => $block, 'field' => 'text'])

    Pass richtext: true for a field backed by <x-form.rich-text> (currently only
    the Paragraph block). That editor is `wire:ignore`d and has no watcher on its
    entangled content (see resources/js/rich-text.js), so a server-side append via
    insertToken() would land in the Livewire property but never reach the editor's
    own document — and get silently overwritten on the next keystroke. A richtext
    field instead dispatches a window event the editor listens for itself and
    inserts at the caret client-side.
--}}

@php($richtext ??= false)

@php($tokenGroups = $this->personalizeTokenGroups())

<flux:dropdown position="bottom" align="end">
    <button type="button" class="text-xs font-medium text-lime-700 hover:text-lime-800 dark:text-lime-400">+ Personalize</button>
    <flux:menu>
        {{-- A heading only once there is something to tell apart — a lone group
             reads the same as the plain list it always was. --}}
        @foreach ($tokenGroups as $heading => $tokens)
            <flux:menu.group :heading="count($tokenGroups) > 1 ? $heading : null">
                @foreach ($tokens as $token => $label)
                    @if ($richtext)
                        <flux:menu.item x-on:click="$dispatch('personalize-token', { token: '{{ $token }}' })">
                            {{ $label }}
                        </flux:menu.item>
                    @else
                        <flux:menu.item wire:click="insertToken('{{ $block['id'] }}', '{{ $field }}', '{{ $token }}')">
                            {{ $label }}
                        </flux:menu.item>
                    @endif
                @endforeach
            </flux:menu.group>
        @endforeach
    </flux:menu>
</flux:dropdown>
