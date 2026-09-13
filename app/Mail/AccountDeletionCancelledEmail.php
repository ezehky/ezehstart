<?php

namespace App\Mail;

use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms that a scheduled deletion was called off.
 *
 * Sent even when the account holder cancelled it themselves and already saw the
 * screen say so: a deletion that stops is a security-relevant change, and the
 * mail is what tells somebody whose mailbox was used without their knowing.
 */
class AccountDeletionCancelledEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->getEmailConfig()['name'].' account is no longer scheduled for deletion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.deletion-cancelled',
        );
    }
}
