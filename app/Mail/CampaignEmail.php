<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One recipient's copy of a campaign, already fully rendered.
 *
 * Deliberately carries a finished subject/HTML pair rather than the campaign's raw
 * blocks — EmailRenderService renders once per recipient (so `{{user.first_name}}`
 * and any dynamic content resolve to that recipient's data), and this mailable's
 * only job is to deliver what it was handed. A campaign picks its own sender rather
 * than one of the fixed EmailSenderEnum cases, so this builds its envelope directly
 * instead of going through WithEmailResolver.
 */
class CampaignEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedHtml,
        public EmailCampaign $campaign,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $from = filter_var($this->campaign->from_email, FILTER_VALIDATE_EMAIL)
            ? new Address($this->campaign->from_email, $this->campaign->from_name)
            : new Address(config('mail.from.address'), config('mail.from.name'));

        return new Envelope(
            subject: $this->renderedSubject,
            from: $from,
            replyTo: $this->campaign->reply_to ? [new Address($this->campaign->reply_to)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedHtml);
    }
}
