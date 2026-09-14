{{--
    The "+ Personalize" control on a text field.

        @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'text'])
--}}

<flux:dropdown position="bottom" align="end">
    <button type="button" class="text-xs font-medium text-lime-700 hover:text-lime-800 dark:text-lime-400">+ Personalize</button>
    <flux:menu>
        @foreach (app(\App\Services\EmailVariableService::class)->knownTokens() as $token => $label)
            <flux:menu.item wire:click="insertToken({{ $index }}, '{{ $field }}', '{{ $token }}')">
                {{ $label }}
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
