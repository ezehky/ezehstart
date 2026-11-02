<?php

namespace App\Mail;

use App\Enums\MailProviderEnum;
use App\Traits\WithEmailResolver;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The "it works" email from Configuration › Email delivery. Sent, not queued:
 * the screen wants the provider's answer, not a queue's.
 */
class DeliveryTestEmail extends Mailable
{
    use WithEmailResolver;

    public function __construct(public MailProviderEnum $provider) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Test email via {$this->provider->label()}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.delivery-test');
    }
}
