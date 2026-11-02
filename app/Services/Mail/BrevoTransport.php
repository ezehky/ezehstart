<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/**
 * Brevo (formerly Sendinblue), over its transactional email API
 * (POST /v3/smtp/email). The sender must be one verified in Brevo.
 */
class BrevoTransport extends ApiTransport
{
    protected function provider(): string
    {
        return 'Brevo';
    }

    protected function deliver(Email $email): Response
    {
        // Brevo rejects a null name, so a nameless address is sent as the email alone.
        $people = fn (array $list) => array_map(fn (array $address) => array_filter($address), $list);

        $payload = array_filter([
            'sender' => $people($this->addresses($email->getFrom()))[0] ?? null,
            'to' => $people($this->addresses($email->getTo())),
            'cc' => $people($this->addresses($email->getCc())) ?: null,
            'bcc' => $people($this->addresses($email->getBcc())) ?: null,
            // Brevo takes one reply-to.
            'replyTo' => $people($this->addresses($email->getReplyTo()))[0] ?? null,
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
            'headers' => $this->customHeaders($email) ?: null,
            'attachment' => array_map(fn (array $file) => [
                'name' => $file['name'],
                'content' => $file['content'],
            ], $this->attachments($email)) ?: null,
        ], fn ($value) => $value !== null && $value !== '');

        return Http::withHeaders(['api-key' => $this->key])
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);
    }
}
