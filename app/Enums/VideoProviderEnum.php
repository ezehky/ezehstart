<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The video hosts the library will accept.
 *
 * This enum is the allowlist, not a convenience. Nothing anywhere trusts a URL an
 * author typed: a video row stores the provider and the id resolved here, and the
 * player URL is rebuilt from those two every time it is rendered. A host with no
 * case below therefore cannot reach a reader however the markup arrived — pasted
 * into the editor, posted straight at the endpoint, or written into the column by
 * hand. An iframe is the one element where trusting a src hands someone else's
 * page a frame on yours, which is why none of the author's own input survives it.
 *
 * Adding a provider is a case here plus a branch in each match below.
 */
enum VideoProviderEnum: string
{
    use WithEnumHelpers;

    case YOUTUBE = 'youtube';
    case VIMEO = 'vimeo';

    public function isYoutube(): bool
    {
        return $this === self::YOUTUBE;
    }

    public function isVimeo(): bool
    {
        return $this === self::VIMEO;
    }

    /**
     * The player URL for one video id on this provider.
     *
     * YouTube gets its -nocookie host: the embed behaves identically but writes no
     * advertising cookie until the reader actually presses play, which is what
     * keeps a post page from needing a consent banner it does not otherwise need.
     */
    public function embedUrl(string $id): string
    {
        return match ($this) {
            self::YOUTUBE => "https://www.youtube-nocookie.com/embed/{$id}",
            self::VIMEO => "https://player.vimeo.com/video/{$id}",
        };
    }

    /**
     * Where a person goes to watch the video on the provider's own site. This is
     * what the library links to, so somebody checking a row does not have to load
     * a bare player to see what it is.
     */
    public function watchUrl(string $id): string
    {
        return match ($this) {
            self::YOUTUBE => "https://www.youtube.com/watch?v={$id}",
            self::VIMEO => "https://vimeo.com/{$id}",
        };
    }

    /**
     * A still frame for the library grid, or null where the provider has no URL
     * that can be built without an API call.
     *
     * Vimeo's thumbnail needs an oEmbed request, and a library grid is not worth a
     * round trip per tile to a third party — those tiles fall back to a placeholder.
     */
    public function thumbnailUrl(string $id): ?string
    {
        return match ($this) {
            self::YOUTUBE => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
            self::VIMEO => null,
        };
    }

    /**
     * Pull the video id out of any URL this provider serves, or null.
     *
     * Writers paste whatever the share button gave them, so every shape is
     * accepted — the watch page, the short link, the shorts and live paths, and an
     * embed URL that has already been through here once.
     */
    public function idFrom(string $url): ?string
    {
        $patterns = match ($this) {
            self::YOUTUBE => [
                '~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/watch\?(?:[^#]*&)?v=([\w-]{6,20})~i',
                '~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/(?:embed|v|shorts|live)/([\w-]{6,20})~i',
                '~^https?://youtu\.be/([\w-]{6,20})~i',
            ],
            self::VIMEO => [
                '~^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{6,12})~i',
                '~^https?://player\.vimeo\.com/video/(\d{6,12})~i',
            ],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($url), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Resolve a pasted URL to the provider that serves it and the id it names.
     *
     * null is the answer for anything no case recognised, and it is the answer the
     * whole allowlist rests on — every caller refuses the video when it comes back.
     *
     * @return array{provider: self, id: string}|null
     */
    public static function resolve(?string $url): ?array
    {
        if (blank($url)) {
            return null;
        }

        foreach (self::cases() as $case) {
            $id = $case->idFrom($url);

            if ($id !== null) {
                return ['provider' => $case, 'id' => $id];
            }
        }

        return null;
    }

    /**
     * The hosts a reader's browser is allowed to frame, for the editor's help text
     * and the validation message. Kept as prose rather than derived from the
     * patterns above, which are not readable enough to show anybody.
     */
    public function domainHint(): string
    {
        return match ($this) {
            self::YOUTUBE => 'youtube.com, youtu.be',
            self::VIMEO => 'vimeo.com',
        };
    }
}
