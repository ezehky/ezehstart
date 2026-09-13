<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The captcha on the guest forms, backed by Cloudflare Turnstile.
 *
 * Deliberately a per-form check rather than a challenge in front of the whole
 * site. A site-wide challenge is a setting on a Cloudflare-proxied domain, not
 * something an administrator can switch on from this dashboard, and the middleware
 * version of it would sit in front of every Livewire round trip, the social
 * callback and any gateway webhook. The form is the thing being abused, so the
 * form is where the token is checked.
 *
 * Nothing here knows which screen it is protecting. Whether a given form asks for
 * a token is WithCaptcha's decision; this only answers "is the captcha available"
 * and "is this token genuine".
 */
#[Singleton]
class CaptchaService
{
    /**
     * Where a token is exchanged for a verdict. The siteverify call is made from
     * the server with the secret, which is the whole point — the widget on the page
     * proves nothing on its own.
     */
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Whether the captcha is offered at all. Both the site switch and a configured
     * key pair have to be true — a switch turned on against empty credentials would
     * render a widget that can never issue a token, and every guest form on the
     * install would become unsubmittable.
     */
    public function isAvailable(): bool
    {
        return (bool) kSiteFlag('security', 'captcha', false) && $this->isConfigured();
    }

    /**
     * Whether this install holds the pair. Read by the admin screen as well, which
     * says so next to the switch rather than letting somebody turn on a feature
     * that silently does nothing.
     */
    public function isConfigured(): bool
    {
        return filled($this->siteKey()) && filled(config('services.turnstile.secret'));
    }

    /**
     * The public half, rendered into the widget. Public by design — it is in the
     * page source on every site that uses Turnstile.
     */
    public function siteKey(): ?string
    {
        return config('services.turnstile.key');
    }

    /**
     * Ask Cloudflare whether this token is one it issued, to us, just now.
     *
     * A token is single use and expires after five minutes, so a form that fails
     * validation has to hand back a fresh one — WithCaptcha::resetCaptcha() is what
     * makes the widget produce it.
     *
     * A network failure returns false rather than throwing. The alternative is a
     * 500 on the sign-in page whenever Cloudflare is unreachable, and a captcha
     * that fails open is not a captcha.
     */
    public function verify(?string $token): bool
    {
        if (blank($token) || ! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::VERIFY_URL, [
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => request()->ip(),
                ]);
        } catch (\Throwable $e) {
            Log::channel('ezeh')->warning('Turnstile verification could not be reached: '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::channel('ezeh')->warning('Turnstile verification returned an unexpected response.', ['status' => $response->status()]);

            return false;
        }

        return (bool) $response->json('success', false);
    }
}
