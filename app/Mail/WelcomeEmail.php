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

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver, WithSystemTemplate;

    public function __construct(
        public User $user,
        public ?string $otp = null,
        public int $expiresInMinutes = 15,
    ) {
        $this->afterCommit();
    }

    protected function systemEmail(): SystemEmailEnum
    {
        return SystemEmailEnum::WELCOME;
    }

    protected function systemRecipient(): ?User
    {
        return $this->user;
    }

    protected function systemContext(): array
    {
        return [
            'welcome' => [
                'code' => (string) $this->otp,
                'expires_minutes' => $this->expiresInMinutes,
                'dashboard_url' => $this->user->user_type->dashboardRoute(),
            ],
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->systemSubject('Welcome to '.$this->getEmailConfig()['name']),
        );
    }

    public function content(): Content
    {
        return $this->systemContent('emails.auth.welcome');
    }
}
