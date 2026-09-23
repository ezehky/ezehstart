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
use Illuminate\Support\Carbon;
use Jenssegers\Agent\Agent;

class LoginEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver, WithSystemTemplate;

    /**
     * Captured when the mail is made, not when the queue sends it: by then the
     * request that signed in is long gone.
     */
    public ?string $device = null;

    public function __construct(
        public User $user,
        public ?string $ipAddress = null,
        public ?Carbon $loginAt = null,
    ) {
        $this->loginAt ??= now();

        $agent = new Agent;
        $this->device = trim($agent->browser().' on '.$agent->platform(), ' on') ?: null;

        $this->afterCommit();
    }

    protected function systemEmail(): SystemEmailEnum
    {
        return SystemEmailEnum::LOGIN;
    }

    protected function systemRecipient(): ?User
    {
        return $this->user;
    }

    protected function systemContext(): array
    {
        return [
            'login' => [
                'ip' => $this->ipAddress ?: 'Unavailable',
                'time' => kDatetimeConverter($this->loginAt, $this->user, dtFormat: true),
                'device' => $this->device ?: 'Unknown device',
            ],
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->systemSubject('New login'),
        );
    }

    public function content(): Content
    {
        return $this->systemContent('emails.auth.login');
    }
}
