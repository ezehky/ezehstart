{{-- The consent checkbox, shared by every screen that creates an account.

     The label is built by hand rather than passed to <flux:label> so it can carry
     real links: somebody agreeing to a policy should be one click from reading it,
     and a checkbox whose terms are not reachable is not consent.

     The policies come from the enum-driven service, so the wording follows whatever
     currently requires consent — publish a new type and this label picks it up. --}}
@props([
    'model' => 'agreed_to_terms',
])

@php($policies = app(App\Services\PolicyContentService::class)->getCurrentRequiringConsent())

<flux:field variant="inline">
    <flux:checkbox wire:model="{{ $model }}" />

    <flux:label>
        @if ($policies->isEmpty())
            I agree to the terms of service and privacy policy.
        @else
            I agree to the
            @foreach ($policies as $policy)
                <flux:link
                    href="{{ $policy->policy_type->url() }}"
                    target="_blank"
                    rel="noopener noreferrer"
                >{{ $policy->title }}</flux:link>@if (! $loop->last)@if ($loop->remaining === 1) and @else, @endif @endif
            @endforeach.
        @endif
    </flux:label>

    <flux:error name="{{ $model }}" />
</flux:field>
