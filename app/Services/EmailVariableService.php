<?php

namespace App\Services;

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
     * @param  array<string, mixed>  $context  Dot-path values, e.g. ['user' => ['first_name' => 'Judith'], 'post' => [...]].
     */
    public function resolve(?string $text, array $context = [], ?User $recipient = null): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        $context = [...$this->baseContext($recipient), ...$context];

        return preg_replace_callback(self::PATTERN, function (array $matches) use ($context) {
            $path = $matches[1];
            $default = $matches[2] ?? '';

            $value = data_get($context, $path);

            return $value !== null && $value !== '' ? (string) $value : $default;
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

    private function firstName(?string $name): string
    {
        return trim(explode(' ', (string) $name, 2)[0] ?? '');
    }

    /**
     * The tokens the "+ Personalize" dropdown offers, [token => label].
     *
     * @return array<string, string>
     */
    public function knownTokens(): array
    {
        return [
            '{{user.first_name | default: "there"}}' => 'First name',
            '{{user.name}}' => 'Full name',
            '{{user.email}}' => 'Email address',
            '{{site.name}}' => 'Site name',
            '{{site.url}}' => 'Site URL',
            '{{unsubscribe_url}}' => 'Unsubscribe link',
        ];
    }
}
