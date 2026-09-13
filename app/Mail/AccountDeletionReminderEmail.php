<?php

namespace App\Mail;

use App\Enums\DeletionReminderEnum;
use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The last-chance warning, sent once per lead time while an account sits in its
 * grace period.
 */
class AccountDeletionReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public DeletionReminderEnum $reminder,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $subject = $this->reminder === DeletionReminderEnum::ONE_DAY
            ? 'Last chance: your account is deleted tomorrow'
            : 'Your account is deleted '.$this->reminder->lead();

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.deletion-reminder',
            with: [
                'restoreUrl' => kAccountRestoreUrl($this->user),
                'scheduledAt' => $this->user->deletion_scheduled_at,
            ],
        );
    }
}
