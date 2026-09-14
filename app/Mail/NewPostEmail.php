<?php

namespace App\Mail;

use App\Models\Post;
use App\Models\User;
use App\Services\NewsletterService;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a subscriber that something new is up.
 *
 * Carries the excerpt rather than the post body: the point of the mail is to get
 * somebody to the page, and an email that reproduces the whole article gives
 * them no reason to go.
 */
class NewPostEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public Post $post,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New post: '.$this->post->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.blog.new-post',
            with: [
                'url' => route('blog.show', $this->post->slug),

                // This is a subscription, so the layout's unsubscribe line is asked
                // for. A transactional mail passes nothing and gets no link.
                'unsubscribeUrl' => app(NewsletterService::class)->unsubscribeUrl($this->user),
            ],
        );
    }
}
