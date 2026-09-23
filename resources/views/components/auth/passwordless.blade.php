@props(['enabled' => false, 'label' => null])
{{-- The switch has to take this button with it. A link to a route that aborts
     is not a disabled feature, it is a dead end with a button on it. --}}
@if ($enabled)
<div class="my-3">
    <flux:button href="{{ route('passwordless') }}" icon="envelope" class="w-full">
        {{ $label ?? __('Email me a sign-in code') }}
    </flux:button>
</div>
@endif
