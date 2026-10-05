<?php

namespace App\Mail;

use App\Enums\SystemEmailEnum;
use App\Models\User;
use App\Traits\WithEmailResolver;
use App\Traits\WithSystemTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "we missed you" nudge to a member who has gone quiet.
 */
class InactivityReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver, WithSystemTemplate;

    public function __construct(
        public User $user,
        public int $daysAway,
    ) {
        $this->afterCommit();
    }

    protected function systemEmail(): SystemEmailEnum
    {
        return SystemEmailEnum::INACTIVITY_REMINDER;
    }

    protected function systemRecipient(): ?User
    {
        return $this->user;
    }

    protected function systemContext(): array
    {
        return [
            'inactivity' => [
                'days' => $this->daysAway,
                'login_url' => route('login'),
            ],
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->systemSubject('We missed you at '.$this->getEmailConfig()['name']),
        );
    }

    public function content(): Content
    {
        return $this->systemContent('emails.auth.missed-you');
    }
}
