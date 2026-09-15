{{--
    The "+ Personalize" control on a text field.

        @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'text'])

    Pass richtext: true for a field backed by <x-form.rich-text> (currently only
    the Paragraph block). That editor is `wire:ignore`d and has no watcher on its
    entangled content (see resources/js/rich-text.js), so a server-side append via
    insertToken() would land in the Livewire property but never reach the editor's
    own document — and get silently overwritten on the next keystroke. A richtext
    field instead dispatches a window event the editor listens for itself and
    inserts at the caret client-side.
--}}

@php($richtext ??= false)

<flux:dropdown position="bottom" align="end">
    <button type="button" class="text-xs font-medium text-lime-700 hover:text-lime-800 dark:text-lime-400">+ Personalize</button>
    <flux:menu>
        @foreach (app(\App\Services\EmailVariableService::class)->knownTokens() as $token => $label)
            @if ($richtext)
                <flux:menu.item x-on:click="$dispatch('personalize-token', { token: '{{ $token }}' })">
                    {{ $label }}
                </flux:menu.item>
            @else
                <flux:menu.item wire:click="insertToken({{ $index }}, '{{ $field }}', '{{ $token }}')">
                    {{ $label }}
                </flux:menu.item>
            @endif
        @endforeach
    </flux:menu>
</flux:dropdown>
