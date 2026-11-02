<?php

namespace App\Mail;

use App\Models\Policy;
use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We have updated our Terms" — sent to every active member when an admin
 * publishes a policy with the email box ticked. A service notice, not
 * marketing: it links to the version now in force and says nothing of its own
 * about what changed, because the intro the admin wrote is the summary.
 */
class PolicyUpdatedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public Policy $policy,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "We have updated our {$this->policy->title}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.policy-updated');
    }
}
