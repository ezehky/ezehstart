<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum SocialHandleEnum: string
{
    use WithEnumHelpers;

    case FACEBOOK = 'facebook';
    case INSTAGRAM = 'instagram';
    case TELEGRAM = 'telegram';
    case X = 'x';
    case TIKTOK = 'tiktok';
    case YOUTUBE = 'youtube';
    case LINKEDIN = 'linkedin';
    case WHATSAPP_GROUP = 'whatsapp-group';
    case PINTEREST = 'pinterest';

    public function icon(): string
    {
        return match ($this) {
            self::WHATSAPP_GROUP,
            self::WHATSAPP_SUPPORT,
            self::WHATSAPP_CHANNEL => 'whatsapp',
            self::TELEGRAM_SUPPORT,
            self::TELEGRAM_CHANNEL => 'telegram',
            default => $this->value
        };
    }

    // ============================Supports
    case WHATSAPP_SUPPORT = 'whatsapp-support';
    case TELEGRAM_SUPPORT = 'telegram-support';

    public function isSupport(): bool
    {
        return \in_array(
            $this,
            [
                self::WHATSAPP_SUPPORT,
                self::TELEGRAM_SUPPORT,
            ]
        );
    }

    public static function supports(): array
    {
        return [
            self::WHATSAPP_SUPPORT,
            self::TELEGRAM_SUPPORT,
        ];
    }

    // ============================Channels
    case WHATSAPP_CHANNEL = 'whatsapp-channel';
    case TELEGRAM_CHANNEL = 'telegram-channel';

    public function isChannel(): bool
    {
        return \in_array(
            $this,
            [
                self::WHATSAPP_CHANNEL,
                self::TELEGRAM_CHANNEL,
            ]
        );
    }

    public static function channels(): array
    {
        return [
            self::WHATSAPP_CHANNEL,
            self::TELEGRAM_CHANNEL,
        ];
    }

    // ============================Personal

    /**
     * The platforms a person is found on, as opposed to the ones a business runs.
     *
     * An author's byline offers these and not the rest: a support desk or a broadcast
     * channel belongs to the site, so offering it on somebody's own profile invites a
     * personal page to be filled in with the company's WhatsApp.
     *
     * @return array<int, self>
     */
    public static function profiles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => ! $case->isSupport()
                && ! $case->isChannel()
                && $case !== self::WHATSAPP_GROUP,
        ));
    }

    /**
     * Where a stored handle points.
     *
     * Handles are kept as typed — "@someone", "someone", or a full URL — because that
     * is what a person pastes, and rewriting it on the way in loses whichever half
     * they meant. A full URL is passed through; anything else is hung off the
     * platform's own address.
     */
    public function urlFor(string $handle): string
    {
        $handle = trim($handle);

        if (str_starts_with($handle, 'http://') || str_starts_with($handle, 'https://')) {
            return $handle;
        }

        $handle = ltrim($handle, '@/');

        return match ($this) {
            self::FACEBOOK => "https://facebook.com/{$handle}",
            self::INSTAGRAM => "https://instagram.com/{$handle}",
            self::X => "https://x.com/{$handle}",
            self::TIKTOK => "https://tiktok.com/@{$handle}",
            self::YOUTUBE => "https://youtube.com/@{$handle}",
            self::LINKEDIN => "https://linkedin.com/in/{$handle}",
            self::TELEGRAM,
            self::TELEGRAM_SUPPORT,
            self::TELEGRAM_CHANNEL => "https://t.me/{$handle}",
            self::WHATSAPP_GROUP,
            self::WHATSAPP_SUPPORT,
            self::WHATSAPP_CHANNEL => "https://wa.me/{$handle}",
            self::PINTEREST => "https://pinterest.com/{$handle}",
        };
    }
}
