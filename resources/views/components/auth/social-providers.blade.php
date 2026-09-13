@props(['providers'])
{{-- Social sign-up. The same callback that signs an existing account in creates
one the first time, so the providers offered here are the providers offered
on the sign-in screen — one list, asked for the same way. --}}
@if ($providers?->isNotEmpty())
<div class="flex items-center gap-3 my-3">
   <flux:separator class="grow" />
   <flux:text size="sm" class="shrink-0">or continue with</flux:text>
   <flux:separator class="grow" />
</div>

<div class="flex items-center justify-center gap-3">
   @foreach ($providers as $provider)
       <flux:button
           wire:key="social-{{ $provider->value }}"
           href="{{ route('social.redirect', $provider->value) }}"
            icon="{{ $provider->icon() }}"
       >
           {{ $provider->label() }}
       </flux:button>
   @endforeach
</div>
@endif
