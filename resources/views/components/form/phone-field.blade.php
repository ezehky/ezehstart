{{-- A phone number with a country picker, over intl-tel-input.

     wire:model names the number. Name the dialling code and the country as well
     and the three are kept apart, the way users.phone_* stores them:

         <x-form.phone-field wire:model="phone_number" dial-code="phone_dial_code" iso2="phone_iso2" />

     Leave them off and the number is written whole, in international format —
     right for a single settings string such as the site's contact number.

     The input sits inside wire:ignore, because intl-tel-input rewrites the markup
     around it and a Livewire morph would tear that back out. The values travel
     through entangled properties instead; see resources/js/phone-input.js. --}}
@props([
    'label' => 'Phone Number',
    'badge' => null,
    'description' => null,
    'dialCode' => null,
    'iso2' => null,
    'defaultCountry' => 'ng',
    'placeholder' => null,
])

@php
    $model = $attributes->wire('model')->value();
@endphp

<flux:field>
    @if ($label)
        <flux:label :badge="$badge">{{ $label }}</flux:label>
    @endif

    <div
        wire:ignore
        x-data="phoneInput({
            number: $wire.$entangle(@js($model)),
            @if ($dialCode) dialCode: $wire.$entangle(@js($dialCode)), @endif
            @if ($iso2) iso2: $wire.$entangle(@js($iso2)), @endif
            defaultCountry: @js($defaultCountry),
        })"
        class="[&_.iti]:w-full"
    >
        <flux:input
            type="tel"
            x-ref="input"
            inputmode="tel"
            autocomplete="tel"
            :placeholder="$placeholder"
            {{ $attributes->whereDoesntStartWith('wire:model') }}
        />
    </div>

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <flux:error :name="$model" />
</flux:field>
