<?php

namespace App\Traits;

trait WithEmailResolver
{
    /**
     * Summary of getEmailConfig
     *
     * @return string|array{name: string, logo: string|null, contactEmail: string|null, email: string|null}
     */
    protected function getEmailConfig(string $key = ''): string|array
    {
        $keys = $key ? [] : ['name', 'logo', 'contact-email', 'email'];

        return kSiteConfig($key, $keys);
    }

    /**
     * Override buildViewData to inject emailConfig
     * MUST BE PUBLIC to match Illuminate\Mail\Mailable
     */
    public function buildViewData(): array
    {
        return [
            ...parent::buildViewData(),
            'emailConfig' => $this->getEmailConfig(),
        ];
    }

    // For Notifications - use in toMail() method
    protected function buildNotificationViewData(): array
    {
        return [
            'emailConfig' => $this->getEmailConfig(),
        ];
    }
}
