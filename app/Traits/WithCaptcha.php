<?php

namespace App\Traits;

use App\Rules\CaptchaRule;
use App\Services\CaptchaService;
use Closure;
use Throwable;

/**
 * The captcha on a guest form: the token property, the rule that checks it, and
 * the decision about whether this screen is asking for one at all.
 *
 * The widget and the rule are added together by captchaRules(), never separately.
 * A page that renders <x-form.captcha> without the rule is decorated rather than
 * protected, and a page that adds the rule without the widget cannot be submitted.
 */
trait WithCaptcha
{
    /**
     * The token the widget produces. Null until the visitor has passed the
     * challenge, and null again the moment the form comes back with an error —
     * Turnstile tokens are single use.
     */
    public ?string $captcha = null;

    /**
     * Whether this screen is asking for a token.
     *
     * The default is "whenever the feature is available", which is what the forms
     * that create an account or send mail want. The login screen overrides it: a
     * correct password on the first try should not have to argue with a widget.
     */
    public function captchaRequired(): bool
    {
        return app(CaptchaService::class)->isAvailable();
    }

    /**
     * Merge the captcha into a rule set. Called from rules() so the check runs on
     * every submit rather than only where the page remembered to ask for it.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function captchaRules(array $rules = []): array
    {
        if ($this->captchaRequired()) {
            $rules['captcha'] = ['required', new CaptchaRule];
        }

        return $rules;
    }

    /**
     * Throw the used token away and tell the widget to issue another.
     *
     * Cloudflare accepts a token once. Without this, a visitor who mistypes their
     * password would be handing back a spent token on the second try and would be
     * told the verification failed when the only thing wrong was the password.
     */
    protected function resetCaptcha(): void
    {
        $this->captcha = null;

        $this->dispatch('captcha-reset');
    }

    /**
     * Livewire hands every exception thrown out of an action through here,
     * including the ValidationException that respondError() raises. That makes it
     * the one place a failed submit is guaranteed to pass through, so it is where
     * the spent token is dropped.
     */
    public function exception(Throwable $e, Closure $stopPropagation): void
    {
        $this->resetCaptcha();
    }
}
