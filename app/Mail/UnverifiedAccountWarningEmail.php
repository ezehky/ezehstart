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
 * The one warning an unverified account gets before the sweep removes it.
 */
class UnverifiedAccountWarningEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver, WithSystemTemplate;

    public function __construct(
        public User $user,
        public int $daysUntilDeletion,
    ) {
        $this->afterCommit();
    }

    protected function systemEmail(): SystemEmailEnum
    {
        return SystemEmailEnum::UNVERIFIED_WARNING;
    }

    protected function systemRecipient(): ?User
    {
        return $this->user;
    }

    protected function systemContext(): array
    {
        return [
            'unverified' => [
                'days_left' => $this->daysUntilDeletion,
                'verify_url' => route('email.verification', ['user' => $this->user->email]),
            ],
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->systemSubject('Verify your email to keep your '.$this->getEmailConfig()['name'].' account'),
        );
    }

    public function content(): Content
    {
        return $this->systemContent('emails.auth.unverified-warning');
    }
}
