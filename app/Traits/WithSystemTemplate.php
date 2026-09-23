<?php

namespace App\Traits;

use App\Enums\SystemEmailEnum;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailRenderService;
use Illuminate\Mail\Mailables\Content;

/**
 * Lets a mailable be redesigned from the email builder.
 *
 * The mailable names its slot and the values its tokens resolve to; this looks
 * for a template assigned to that slot and, when there is one, sends the
 * template instead of the Blade view. When there is none — or it renders to
 * nothing — the view goes out as it always did.
 *
 *     use WithSystemTemplate;
 *
 *     protected function systemEmail(): SystemEmailEnum { return SystemEmailEnum::LOGIN; }
 *     protected function systemRecipient(): ?User { return $this->user; }
 *     protected function systemContext(): array { return ['login' => [...]]; }
 *
 *     public function envelope(): Envelope
 *     {
 *         return new Envelope(subject: $this->systemSubject('New login'));
 *     }
 *
 *     public function content(): Content
 *     {
 *         return $this->systemContent('emails.auth.login');
 *     }
 *
 * Resolved at send time, on the queue worker, so a template edited between the
 * dispatch and the send is the one that goes out.
 */
trait WithSystemTemplate
{
    /**
     * The rendered template for this send, false once looked for and not found.
     *
     * @var array{subject: string, html: string}|false|null
     */
    private array|false|null $systemRender = null;

    abstract protected function systemEmail(): SystemEmailEnum;

    abstract protected function systemRecipient(): ?User;

    /**
     * The mail's own tokens, as dot-path values — ['login' => ['ip' => ...]].
     *
     * @return array<string, mixed>
     */
    abstract protected function systemContext(): array;

    protected function systemSubject(string $fallback): string
    {
        return ($this->systemRender() ?: [])['subject'] ?? $fallback;
    }

    protected function systemContent(string $view): Content
    {
        $render = $this->systemRender();

        return $render ? new Content(htmlString: $render['html']) : new Content(view: $view);
    }

    /**
     * @return array{subject: string, html: string}|false
     */
    private function systemRender(): array|false
    {
        if ($this->systemRender !== null) {
            return $this->systemRender;
        }

        $template = EmailTemplate::query()->forSystemEmail($this->systemEmail())->first();

        // No template, or an empty canvas somebody assigned before designing it.
        // The account holder still gets their mail, from the view, not a blank.
        if (! $template || empty($template->content['blocks'])) {
            return $this->systemRender = false;
        }

        return $this->systemRender = app(EmailRenderService::class)->renderSystemTemplate(
            $template,
            $this->systemEmail(),
            $this->systemRecipient(),
            $this->systemContext(),
        );
    }
}
