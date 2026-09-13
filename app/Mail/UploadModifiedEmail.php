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

/**
 * Tells a member that somebody at the site changed something in their library.
 *
 * Their uploads are private to them, so a change they did not make is the sort
 * of thing they should hear about rather than discover.
 */
class UploadModifiedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    /**
     * @param  string  $summary  What happened, in a sentence.
     */
    public function __construct(
        public User $user,
        public string $summary,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A change to your library on '.$this->getEmailConfig()['name']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.upload-modified');
    }
}
