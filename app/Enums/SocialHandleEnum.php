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
}
