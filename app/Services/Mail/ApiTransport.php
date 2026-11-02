<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * The shared half of the HTTP mail transports: turning the message Laravel
 * built into addresses, bodies and attachments, and failing loudly when the
 * provider says no — a queued mail then retries, and a send from a screen
 * shows the provider's own reason.
 *
 * Each provider supplies only its request (ResendTransport, BrevoTransport).
 * Plain Http:: like the payment gateways: no SDK to install or keep in step.
 */
abstract class ApiTransport extends AbstractTransport
{
    public function __construct(protected string $key)
    {
        parent::__construct();
    }

    /**
     * Send one message to the provider.
     */
    abstract protected function deliver(Email $email): Response;

    /**
     * The provider's name in error messages and the mailer's string form.
     */
    abstract protected function provider(): string;

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $response = $this->deliver($email);

        if ($response->failed()) {
            throw new TransportException(sprintf(
                '%s refused the email (%d): %s',
                $this->provider(),
                $response->status(),
                (string) ($response->json('message') ?? $response->json('error.message') ?? $response->body()),
            ));
        }
    }

    public function __toString(): string
    {
        return strtolower($this->provider()).'+api://';
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<array{email: string, name: ?string}>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => [
            'email' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ], array_values($addresses));
    }

    /**
     * @return list<array{name: string, content: string, type: ?string, inline: bool, cid: ?string}>
     */
    protected function attachments(Email $email): array
    {
        return array_map(fn ($part) => [
            'name' => (string) ($part->getFilename() ?? 'attachment'),
            'content' => base64_encode($part->getBody()),
            'type' => $part->getContentType(),
            'inline' => $part->getDisposition() === 'inline',
            'cid' => $part->getDisposition() === 'inline' ? $part->getContentId() : null,
        ], array_values($email->getAttachments()));
    }

    /**
     * Custom headers the message carries (List-Unsubscribe and the like),
     * without the ones the provider builds from the payload itself.
     *
     * @return array<string, string>
     */
    protected function customHeaders(Email $email): array
    {
        $skip = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'mime-version', 'date', 'message-id', 'return-path'];
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (! in_array(strtolower($header->getName()), $skip, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }
}
