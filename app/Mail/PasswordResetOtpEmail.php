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

class PasswordResetOtpEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public string $otp,
        public int $expiresInMinutes = 10,
        public array $site = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->getEmailConfig('name').' password reset code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.password-reset-otp',
        );
    }
}
