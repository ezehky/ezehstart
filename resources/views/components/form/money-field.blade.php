{{-- An amount of money, typed with thousands separators and handed to Livewire as
     a plain number:

         <x-form.money-field wire:model="amount" label="Amount" />

     The property receives 12500.5, never "12,500.50", so rules(), MoneyRule and
     MoneyCast work on it unchanged. Any wire:model modifier carries through —
     wire:model.live updates as the amount is typed.

     The symbol is the site default currency's, because that is the currency every
     stored amount is in. Pass :currency with another kActiveCurrency()-shaped
     array for a form that takes a different one.

     wire:ignore keeps a re-render from rewriting the text under the caret; the
     value travels through the entangled property instead. See
     resources/js/money-input.js. --}}
@props([
    'label' => null,
    'badge' => null,
    'description' => null,
    'currency' => null,
    'decimals' => 2,
    'min' => null,
    'max' => null,
    'placeholder' => null,
])

@php
    $model = $attributes->wire('model');
    $currency ??= kDefaultCurrency();

    // A range in the placeholder says what the field will accept before anybody
    // has to be told by a validation error.
    $placeholder ??= $min !== null && $max !== null && $min < $max
        ? kMoney((float) $min).' – '.kMoney((float) $max)
        : '0.00';

    // .live and .blur on wire:model become the entangle's own modifier, so the
    // property updates exactly as eagerly as the caller asked for.
    $entangle = $model->hasModifier('live') || $model->hasModifier('blur')
        ? '$wire.$entangle('.Js::from($model->value()).', true)'
        : '$wire.$entangle('.Js::from($model->value()).')';
@endphp

<flux:field>
    @if ($label)
        <flux:label :badge="$badge">{{ $label }}</flux:label>
    @endif

    <div wire:ignore x-data="moneyInput({ value: {{ $entangle }}, decimals: @js((int) $decimals) })">
        <flux:input.group>
            <flux:input.group.prefix>{!! $currency['symbol'] !!}</flux:input.group.prefix>

            <flux:input
                x-model="display"
                x-mask:dynamic="$money($input, '.', ',', decimals)"
                inputmode="decimal"
                autocomplete="off"
                :placeholder="$placeholder"
                {{ $attributes->whereDoesntStartWith('wire:model') }}
            />

            <flux:input.group.suffix>{{ $currency['code'] }}</flux:input.group.suffix>
        </flux:input.group>
    </div>

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <flux:error :name="$model->value()" />
</flux:field>
