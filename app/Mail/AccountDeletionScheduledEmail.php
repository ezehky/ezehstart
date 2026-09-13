<?php

namespace App\Mail;

use App\Models\User;
use App\Traits\WithEmailResolver;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms that the account is now inside its grace period, and carries the one
 * link that takes it back out again.
 */
class AccountDeletionScheduledEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public CarbonInterface $scheduledAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->getEmailConfig()['name'].' account is scheduled for deletion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.deletion-scheduled',
            with: [
                'restoreUrl' => kAccountRestoreUrl($this->user),
            ],
        );
    }
}
