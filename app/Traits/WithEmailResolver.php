<?php

namespace App\Traits;

use App\Enums\EmailSenderEnum;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;

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

    /**
     * The email "from" address.
     */
    protected Address $emailFrom;

    protected ?string $queueName = null;

    protected array $replyToArray = [];

    /**
     * Set the email "from" address based on the provided sender enum and optional parameters.
     *
     * @param  EmailSenderEnum  $sender  The email sender enum.
     * @param  string|null  $username  Optional username for custom sender.
     * @param  string|null  $fromName  Optional name for the "from" address.
     * @param  array|null  $extraReplyTo  Optional extra reply-to addresses. [email => name, email, ...]
     */
    protected function setEmailFrom(
        EmailSenderEnum $sender = EmailSenderEnum::DEFAULT,
        ?string $username = null, // Username for custom sender, e.g., "ezehstart"
        ?string $fromName = null,
        ?array $extraReplyTo = null //
    ): void {
        $siteConfig = $this->getEmailConfig();
        $sender = kSiteConfig("email-senders.{$sender->value}");
        $from = data_get($sender, 'from');
        $fromName ??= data_get($sender, 'from-name', $siteConfig['name']);

        // Set the default "from" address using the site configuration
        $defaultFrom = new Address(config('mail.from.address'), data_get($siteConfig, 'name', 'Ezehstart'));

        // Determine the email sender based on the provided sender enum
        if ($from === null) {
            // Get the current "from" address from the configuration
            $this->emailFrom = $defaultFrom;

            return;
        }

        // Custom logic to handle the "from" address based on the sender enum
        if ($sender->isCustom()) {
            if (! $username) {
                Log::channel('ezeh')->error('Custom email sender requires a username to construct the "from" address.');
                throw new \InvalidArgumentException('Custom email sender requires a username to construct the "from" address.');
            }

            $from = kStripDomainProtocols($from, $username);
        }

        // If from is not a valid email address, fallback to default
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            Log::channel('ezeh')->error("Invalid 'from' email address: {$from}. Falling back to default.");
            $this->emailFrom = $defaultFrom;

            return;
        }

        // Set the "from" address using the provided or default values
        $this->emailFrom = new Address($from, $fromName);

        // ==================================================
        // Reply
        $configReplyTo = data_get($sender, 'reply-to');
        $configReplyToName = data_get($sender, 'reply-to-name', $fromName);
        $replyTo = [];

        // If a reply-to address is available, use it
        if ($configReplyTo || filter_var($configReplyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($configReplyTo, $configReplyToName);
        }

        // If extra reply-to addresses are provided, add them to the reply-to array
        if ($extraReplyTo) {
            foreach ($extraReplyTo as $email => $name) {
                // If the key is an integer, it means the value is the email address
                if (\is_int($email)) {
                    $email = $name;
                    $name = null;
                }
                // Validate the email address before adding it to the reply-to array
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $replyTo[] = new Address($email, $name);
                } else {
                    Log::channel('ezeh')->warning("Invalid extra reply-to email address: {$email}. Skipping.");
                }
            }
        }

        // Set the reply-to addresses
        $this->replyToArray = $replyTo;
    }
}
