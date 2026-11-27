<?php

namespace App\Services;

use App\Enums\SocialHandleEnum;
use App\Models\Post;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * The schema.org JSON-LD that search engines read beside a page — the site
 * itself on the home page, and a post on its own page.
 *
 * Built only from what the page already shows, so it can never claim more than
 * a visitor sees. Printed by <x-site.structured-data>, which the controller
 * hands the array to; nothing here reaches the page any other way.
 */
#[Singleton]
class StructuredDataService
{
    /**
     * The organisation behind the site, for the home page: its name, address
     * on the web, logo once one is uploaded, and the profiles it lists under
     * Configuration › Social handles.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->siteName(),
            'url' => route('home'),
            'logo' => $this->logo(),
            'sameAs' => $this->socialProfiles()->values()->all() ?: null,
        ]);
    }

    /**
     * A blog post, with its byline and dates.
     *
     * @return array<string, mixed>
     */
    public function article(Post $post): array
    {
        $logo = $this->logo();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            // Google cuts a headline at 110 characters; a longer one is ignored.
            'headline' => str($post->metaTitleOrDefault())->limit(110, '')->toString(),
            'description' => $post->metaDescriptionOrDefault(),
            'image' => $post->image?->url(),
            'datePublished' => $post->published_at?->toAtomString(),
            'dateModified' => $post->updated_at?->toAtomString(),
            'author' => ['@type' => 'Person', 'name' => $post->authorName()],
            'keywords' => $post->tags->pluck('name')->implode(', ') ?: null,
            'publisher' => array_filter([
                '@type' => 'Organization',
                'name' => $this->siteName(),
                'logo' => $logo ? ['@type' => 'ImageObject', 'url' => $logo] : null,
            ]),
            'mainEntityOfPage' => route('blog.show', $post),
        ]);
    }

    private function siteName(): string
    {
        return (string) (kSiteConfig('name') ?: config('app.name'));
    }

    /**
     * The uploaded logo, or null. kSiteConfig() always answers with a logo —
     * the kit's own artwork stands in until one is uploaded — and that artwork
     * is not this organisation's mark, so it is never offered as one.
     */
    private function logo(): ?string
    {
        $uploaded = data_get(app(SiteConfigurationService::class)->getConfigs(raw: true), 'logo');

        return filled($uploaded) ? (string) kSiteConfig('logo') : null;
    }

    /**
     * The profile links Configuration › Social handles lists, as plain URLs.
     * Support numbers are a way to reach somebody, not a profile, so they are
     * left out.
     *
     * @return Collection<int, string>
     */
    private function socialProfiles(): Collection
    {
        return collect(kSiteConfig('social-handles', default: []) ?: [])
            ->filter(function ($handle) {
                $platform = SocialHandleEnum::tryFrom((string) data_get($handle, 'platform'));

                return $platform && ! $platform->isSupport() && filled(data_get($handle, 'url'));
            })
            ->map(fn ($handle) => (string) data_get($handle, 'url'));
    }
}
