{{-- The Cloudflare Turnstile challenge, shared by every guest form that asks for one.

     Whether it appears at all is the page's decision — WithCaptcha::captchaRequired()
     — not this component's, because the login screen only asks once an address has
     been failing sign-ins. This renders the widget and nothing else.

     The widget is wrapped in wire:ignore because Cloudflare owns the iframe inside
     it: a Livewire patch that replaced the node would tear down a live challenge and
     lose the token with it. --}}
@props([
    'action' => null,
    'model' => 'captcha',
])

@php($siteKey = app(App\Services\CaptchaService::class)->siteKey())

@if ($siteKey)
    <div>
        <div
            wire:ignore
            x-data="turnstileWidget({
                sitekey: @js($siteKey),
                action: @js($action),
                model: @js($model),
            })"
            x-on:captcha-reset.window="reset()"
        >
            <div x-ref="widget" class="min-h-[65px]"></div>
        </div>

        <flux:error name="{{ $model }}" />
    </div>
@endif
