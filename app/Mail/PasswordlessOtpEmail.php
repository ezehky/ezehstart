<?php

namespace App\Mail;

use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The code that signs a visitor in without a password. It takes plain strings rather
 * than a User, because a registration code is sent before the account is created.
 */
class PasswordlessOtpEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public string $name,
        public string $email,
        public string $otp,
        public int $expiresInMinutes,
        public bool $isNewAccount = false,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $action = $this->isNewAccount ? 'sign-up' : 'sign-in';

        return new Envelope(
            subject: 'Your '.$this->getEmailConfig()['name'].' '.$action.' code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.passwordless-otp',
        );
    }
}
