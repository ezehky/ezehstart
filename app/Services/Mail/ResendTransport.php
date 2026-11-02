<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/**
 * Resend, over its REST API (POST /emails). Registered in AppServiceProvider
 * in place of Laravel's own resend transport, which needs the Resend SDK.
 */
class ResendTransport extends ApiTransport
{
    protected function provider(): string
    {
        return 'Resend';
    }

    protected function deliver(Email $email): Response
    {
        $format = fn (array $list) => array_map(fn (array $address) => $address['name'] ? "{$address['name']} <{$address['email']}>" : $address['email'], $list);

        $from = $this->addresses($email->getFrom())[0] ?? null;

        $payload = array_filter([
            'from' => $from ? $format([$from])[0] : null,
            'to' => $format($this->addresses($email->getTo())),
            'cc' => $format($this->addresses($email->getCc())) ?: null,
            'bcc' => $format($this->addresses($email->getBcc())) ?: null,
            'reply_to' => $format($this->addresses($email->getReplyTo())) ?: null,
            'subject' => (string) $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'headers' => $this->customHeaders($email) ?: null,
            'attachments' => array_map(fn (array $file) => array_filter([
                'filename' => $file['name'],
                'content' => $file['content'],
                'content_type' => $file['type'],
                'content_id' => $file['cid'],
            ]), $this->attachments($email)) ?: null,
        ], fn ($value) => $value !== null && $value !== '');

        return Http::withToken($this->key)
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.resend.com/emails', $payload);
    }
}
