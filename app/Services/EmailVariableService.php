<?php

namespace App\Services;

use App\Enums\SocialHandleEnum;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;

/**
 * Resolves `{{token}}` / `{{token | default: "fallback"}}` strings in a campaign's
 * subject, preview text, and block content. One resolve() call is what both
 * EmailRenderService (block content) and EmailCampaignService (subject/preview) go
 * through, so a token behaves the same wherever it is typed.
 */
#[Singleton]
class EmailVariableService
{
    /**
     * `{{token}}` and `{{token | default: "text"}}`, single-quotes accepted too.
     */
    private const PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*default:\s*[\'"]([^\'\"]*)[\'"]\s*)?\}\}/';

    /**
     * The one token whose substituted value is already-safe markup rather than
     * text an admin typed — see baseContext(). Only this path skips escaping in
     * `html: true` mode.
     */
    private const RAW_HTML_TOKEN = 'site.social_links';

    /**
     * @param  array<string, mixed>  $context  Dot-path values, e.g. ['user' => ['first_name' => 'Judith'], 'post' => [...]].
     * @param  bool  $html  When true, a substituted value is HTML-escaped before
     *                      insertion — the text is about to be dropped into HTML
     *                      (a richtext paragraph or an HTML block) rather than
     *                      escaped as a whole afterward. site.social_links is the
     *                      one exception: it is server-built markup, never typed
     *                      by an admin, so it is inserted raw.
     */
    public function resolve(?string $text, array $context = [], ?User $recipient = null, bool $html = false): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        $context = [...$this->baseContext($recipient), ...$context];

        return preg_replace_callback(self::PATTERN, function (array $matches) use ($context, $html) {
            $path = $matches[1];
            $default = $matches[2] ?? '';

            $value = data_get($context, $path);
            $resolved = $value !== null && $value !== '' ? (string) $value : $default;

            return $html && $path !== self::RAW_HTML_TOKEN ? e($resolved) : $resolved;
        }, $text) ?? $text;
    }

    /**
     * Every token available regardless of what block is asking — site identity, the
     * recipient (when there is one), and the unsubscribe link.
     *
     * @return array<string, mixed>
     */
    private function baseContext(?User $recipient): array
    {
        $context = [
            'site' => [
                'name' => (string) kSiteConfig('name'),
                'url' => config('app.url'),
                'email' => (string) kSiteConfig('email'),
                'contact_email' => (string) kSiteConfig('contact-email'),
                // kSiteConfig()'s own default is an empty array, and phone/address
                // both start as an empty string — falsy, so an unconfigured
                // install would otherwise get that array back and (string) it,
                // an "Array to string conversion" error rather than "".
                'phone' => (string) kSiteConfig('phone', default: ''),
                'address' => (string) kSiteConfig('address', default: ''),
                'social' => $this->socialUrls(),
                'social_links' => $this->socialLinksHtml(),
            ],
            'unsubscribe_url' => '#',
        ];

        if ($recipient) {
            $context['user'] = [
                'name' => $recipient->name,
                'first_name' => $this->firstName($recipient->name),
                'email' => $recipient->email,
            ];

            $context['unsubscribe_url'] = app(NewsletterService::class)->unsubscribeUrl($recipient);
        }

        return $context;
    }

    /**
     * The configured social handles, keyed by platform, as {{site.social.facebook}}
     * and friends resolve to.
     *
     * @return array<string, string>
     */
    private function socialUrls(): array
    {
        return collect($this->socialHandles())
            ->mapWithKeys(fn (array $handle) => [$handle['platform'] => $handle['url']])
            ->all();
    }

    /**
     * A small row of plain-text social links, already escaped on the way in — the
     * one token that is inserted raw in html mode (see resolve()) because it is
     * built here, not typed by an admin. Empty on an install with no handles
     * configured, which renders as nothing rather than a broken row.
     */
    private function socialLinksHtml(): string
    {
        $links = collect($this->socialHandles())
            ->map(function (array $handle) {
                $label = SocialHandleEnum::tryFrom($handle['platform'])?->label() ?? $handle['platform'];

                return sprintf('<a href="%s" style="color:inherit;text-decoration:underline;">%s</a>', e($handle['url']), e($label));
            });

        return $links->implode(' &middot; ');
    }

    /**
     * @return array<int, array{platform: string, url: string}>
     */
    private function socialHandles(): array
    {
        return (array) kSiteConfig('social-handles', default: []);
    }

    private function firstName(?string $name): string
    {
        return trim(explode(' ', (string) $name, 2)[0] ?? '');
    }

    /**
     * The tokens the "+ Personalize" dropdown offers, [token => label].
     *
     * Site identity that is a piece of text — a name, an address — belongs here.
     * Anything visual (the logo, the favicon, a social icon) is a block on the
     * canvas instead (see EmailBlockTypeEnum's "Site Config" group), not a token
     * dropped into running text.
     *
     * @return array<string, string>
     */
    public function knownTokens(): array
    {
        $tokens = [
            '{{user.first_name | default: "there"}}' => 'First name',
            '{{user.name}}' => 'Full name',
            '{{user.email}}' => 'Email address',
            '{{site.name}}' => 'Site name',
            '{{site.url}}' => 'Site URL',
            '{{site.email}}' => 'Site email',
            '{{site.contact_email}}' => 'Support email',
            '{{site.phone}}' => 'Site phone',
            '{{site.address}}' => 'Site address',
            '{{unsubscribe_url}}' => 'Unsubscribe link',
        ];

        $handles = collect($this->socialHandles());

        foreach ($handles as $handle) {
            $label = SocialHandleEnum::tryFrom($handle['platform'])?->label() ?? $handle['platform'];
            $tokens["{{site.social.{$handle['platform']}}}"] = "{$label} link";
        }

        if ($handles->isNotEmpty()) {
            $tokens['{{site.social_links}}'] = 'Social links row';
        }

        return $tokens;
    }
}
