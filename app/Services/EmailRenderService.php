<?php

namespace App\Services;

use App\Enums\EmailBlockTypeEnum;
use App\Models\EmailCampaign;
use App\Models\EmailSection;
use App\Models\Image;
use App\Models\User;
use App\Traits\WithRichTextSanitizer;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Turns a block array into email-safe, table-based HTML — the one place that knows
 * what a "heading" block or a "dynamic_content" block actually renders as.
 *
 * Every dynamic block is resolved here, at render time, against
 * DynamicContentRegistryService — never against a copy of the content itself, so a
 * post's title or image changing before a scheduled send always reaches the email
 * with today's data. A sent campaign's "snapshot" is EmailCampaignRecipient's own
 * row of what actually went out, not a second copy of the content.
 */
#[Singleton]
class EmailRenderService
{
    use WithRichTextSanitizer;

    /**
     * @return array{subject: string, html: string}
     */
    public function renderCampaign(EmailCampaign $campaign, ?User $recipient = null): array
    {
        $variables = app(EmailVariableService::class);

        $body = $this->renderBlocks($campaign->content['blocks'] ?? [], $recipient);
        $body .= $this->renderFooter($campaign->footerSection, $recipient);

        return [
            'subject' => $variables->resolve($campaign->subject, recipient: $recipient),
            'html' => $this->document($body, $campaign->design ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function renderBlocks(array $blocks, ?User $recipient = null): string
    {
        return collect($blocks)
            ->map(fn (array $block) => $this->renderBlock($block, $recipient))
            ->implode('');
    }

    private function renderFooter(?EmailSection $footer, ?User $recipient): string
    {
        if (! $footer) {
            return '';
        }

        // A section stores {"blocks": [...]}, the same shape a campaign does — the
        // blocks live one key in, never at the top of the column.
        return $this->renderBlocks($footer->content['blocks'] ?? [], $recipient);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderBlock(array $block, ?User $recipient): string
    {
        $type = EmailBlockTypeEnum::tryFrom($block['type'] ?? '');

        if ($type === null) {
            return '';
        }

        $data = $block['data'] ?? [];
        $variables = app(EmailVariableService::class);
        $text = fn (?string $value) => e($variables->resolve($value, recipient: $recipient));

        return match ($type) {
            EmailBlockTypeEnum::HEADING => $this->row(sprintf(
                '<%1$s style="margin:0;font-family:Arial,sans-serif;text-align:%2$s;color:%3$s;">%4$s</%1$s>',
                // Read once, then checked: `$data['level'] ?? 'h1'` passing the
                // check says nothing about the key existing.
                in_array($level = $data['level'] ?? 'h1', ['h1', 'h2', 'h3'], true) ? $level : 'h1',
                $data['align'] ?? 'center',
                $data['color'] ?? '#0F172A',
                $text($data['text'] ?? ''),
            ), '34px 40px 10px'),

            EmailBlockTypeEnum::PARAGRAPH => $this->row(sprintf(
                '<div style="margin:0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;text-align:%s;color:%s;">%s</div>',
                $data['align'] ?? 'left',
                $data['color'] ?? '#475569',
                $this->sanitize($variables->resolve($data['text'] ?? '', recipient: $recipient, html: true)),
            ), '10px 40px'),

            EmailBlockTypeEnum::BUTTON => $this->row(sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:%s;"><tr><td style="background:%s;border-radius:8px;"><a href="%s"%s style="display:inline-block;padding:12px 26px;font-family:Arial,sans-serif;font-weight:bold;font-size:14px;color:%s;text-decoration:none;">%s</a></td></tr></table>',
                match ($data['align'] ?? 'center') {
                    'left' => '0 auto 0 0',
                    'right' => '0 0 0 auto',
                    default => '0 auto',
                },
                $data['background'] ?? '#A3E635',
                $text($data['url'] ?? '#') ?: '#',
                ! empty($data['new_tab']) ? ' target="_blank" rel="noopener"' : '',
                $data['color'] ?? '#0F172A',
                $text($data['text'] ?? 'Click here'),
            ), '10px 40px 30px'),

            EmailBlockTypeEnum::DIVIDER => $this->row(sprintf(
                '<hr style="border:none;border-top:1px solid %s;margin:0;">',
                $data['color'] ?? '#E2E8F0',
            ), '10px 40px'),

            EmailBlockTypeEnum::SPACER => $this->row('&nbsp;', '0', (int) ($data['height'] ?? 24).'px'),

            EmailBlockTypeEnum::IMAGE => $this->renderImage($data, $text),

            EmailBlockTypeEnum::HTML => $this->row(
                $this->sanitize($variables->resolve($data['html'] ?? '', recipient: $recipient, html: true)),
                '10px 40px',
            ),

            EmailBlockTypeEnum::DYNAMIC_CONTENT => $this->renderDynamicContent($data),

            EmailBlockTypeEnum::RELATED_CONTENT => $this->renderRelatedContent($data),

            EmailBlockTypeEnum::COLUMNS => $this->renderColumns($data, $text),

            EmailBlockTypeEnum::SECTION => $this->renderSectionReference($data, $recipient),
        };
    }

    private function renderImage(array $data, \Closure $text): string
    {
        $image = ! empty($data['image_id']) ? Image::query()->find($data['image_id']) : null;

        if (! $image) {
            return '';
        }

        $img = sprintf(
            '<img src="%s" alt="%s" width="%s" style="display:block;width:%s;max-width:100%%;border-radius:%spx;margin:0 %s;" />',
            $image->url(),
            e($data['alt'] ?? ''),
            (int) str_replace('%', '', (string) ($data['width'] ?? '100%')) ?: 600,
            $data['width'] ?? '100%',
            (int) ($data['radius'] ?? 0),
            ($data['align'] ?? 'center') === 'center' ? 'auto' : '0',
        );

        if ($url = $text($data['link_url'] ?? '')) {
            $img = sprintf('<a href="%s" style="text-decoration:none;">%s</a>', $url, $img);
        }

        return $this->row(sprintf('<div style="text-align:%s;">%s</div>', $data['align'] ?? 'center', $img), '10px 40px');
    }

    private function renderDynamicContent(array $data): string
    {
        $provider = app(DynamicContentRegistryService::class)->provider($data['content_type'] ?? 'post');

        if (! $provider) {
            return '';
        }

        $limit = max(1, (int) ($data['limit'] ?? 1));

        $items = match ($data['mode'] ?? 'latest') {
            'specific' => ! empty($data['content_id']) ? collect([$provider->find((int) $data['content_id'])])->filter() : collect(),
            'category' => ! empty($data['category_id']) ? $provider->byCategory((int) $data['category_id'], $limit) : collect(),
            'tag' => ! empty($data['tag_id']) ? $provider->byTag((int) $data['tag_id'], $limit) : collect(),
            default => $provider->latest($limit),
        };

        $cards = $items->take($limit)->map(fn ($item) => $provider->toCard($item));

        return $this->row($this->cardsGrid($cards, $data), '10px 40px 30px');
    }

    private function renderRelatedContent(array $data): string
    {
        $provider = app(DynamicContentRegistryService::class)->provider($data['content_type'] ?? 'post');

        if (! $provider || empty($data['source_content_id'])) {
            return '';
        }

        $source = $provider->find((int) $data['source_content_id']);

        if (! $source) {
            return '';
        }

        $limit = max(1, (int) ($data['limit'] ?? 3));
        $cards = $provider->related($source, $limit)->map(fn ($item) => $provider->toCard($item));

        $heading = sprintf(
            '<p style="margin:0 0 12px;text-align:center;font-family:Arial,sans-serif;font-weight:bold;font-size:14px;color:#0F172A;">%s</p>',
            e($data['heading'] ?? 'You May Also Like'),
        );

        return $this->row($heading.$this->cardsGrid($cards, $data), '0 40px 34px');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function cardsGrid(Collection $cards, array $data): string
    {
        if ($cards->isEmpty()) {
            return '';
        }

        $buttonText = e($data['button_text'] ?? 'Read More');
        $showImage = $data['show_image'] ?? true;
        $showExcerpt = $data['show_excerpt'] ?? true;
        $showDate = $data['show_date'] ?? false;

        $cells = $cards->map(function (array $card) use ($buttonText, $showImage, $showExcerpt, $showDate) {
            $image = $showImage && $card['image_url']
                ? sprintf('<img src="%s" alt="" style="display:block;width:100%%;border-radius:8px;margin-bottom:10px;" />', $card['image_url'])
                : '';

            $excerpt = $showExcerpt && $card['excerpt']
                ? sprintf('<p style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:13px;color:#64748B;line-height:1.5;">%s</p>', e($card['excerpt']))
                : '';

            $date = $showDate && $card['date']
                ? sprintf('<p style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:11px;color:#94A3B8;">%s</p>', e($card['date']))
                : '';

            return sprintf(
                '<td valign="top" style="padding:0 8px;"><a href="%s" style="text-decoration:none;">%s</a><p style="margin:0;font-family:Arial,sans-serif;font-weight:bold;font-size:13px;color:#0F172A;"><a href="%s" style="color:#0F172A;text-decoration:none;">%s</a></p>%s%s<p style="margin:10px 0 0;font-family:Arial,sans-serif;font-weight:bold;font-size:12px;"><a href="%s" style="color:#65A30D;text-decoration:none;">%s &rarr;</a></p></td>',
                $card['url'],
                $image,
                $card['url'],
                e($card['title']),
                $excerpt,
                $date,
                $card['url'],
                $buttonText,
            );
        })->implode('');

        return sprintf('<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr>%s</tr></table>', $cells);
    }

    private function renderColumns(array $data, \Closure $text): string
    {
        $columns = collect($data['columns'] ?? [])->map(fn (array $column) => sprintf(
            '<td valign="top" style="padding:0 12px;font-family:Arial,sans-serif;font-size:14px;color:#475569;">%s</td>',
            nl2br($text($column['text'] ?? '')),
        ))->implode('');

        return $this->row(sprintf('<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr>%s</tr></table>', $columns), '10px 40px');
    }

    private function renderSectionReference(array $data, ?User $recipient): string
    {
        if (empty($data['email_section_id'])) {
            return '';
        }

        $section = EmailSection::query()->find($data['email_section_id']);

        return $section ? $this->renderBlocks($section->content['blocks'] ?? [], $recipient) : '';
    }

    /**
     * One block, wrapped in the table row every email-safe block needs.
     */
    private function row(string $inner, string $padding, string $height = ''): string
    {
        $style = "padding:{$padding};".($height !== '' ? "height:{$height};line-height:{$height};font-size:1px;" : '');

        return sprintf('<tr><td style="%s">%s</td></tr>', $style, $inner);
    }

    /**
     * The full document: the design settings, the container, and everything rendered
     * so far inside it.
     *
     * @param  array<string, mixed>  $design
     */
    private function document(string $body, array $design): string
    {
        $background = $design['background'] ?? '#F1F5F9';
        $containerBackground = $design['container_background'] ?? '#FFFFFF';
        $width = (int) ($design['container_width'] ?? 640);
        $fontFamily = $design['font_family'] ?? 'Arial, sans-serif';

        return <<<HTML
        <!doctype html>
        <html>
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:{$background};font-family:{$fontFamily};">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$background};">
        <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="{$width}" cellpadding="0" cellspacing="0" style="width:{$width}px;max-width:100%;background:{$containerBackground};border-radius:6px;">
        {$body}
        </table>
        </td></tr>
        </table>
        </body>
        </html>
        HTML;
    }
}
