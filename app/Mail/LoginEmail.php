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
use Illuminate\Support\Carbon;

class LoginEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public ?string $ipAddress = null,
        public ?Carbon $loginAt = null,
    ) {
        $this->loginAt ??= now();
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New login',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.login',
        );
    }
}
