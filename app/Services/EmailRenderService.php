<?php

namespace App\Services;

use App\Enums\EmailBlockTypeEnum;
use App\Enums\SocialHandleEnum;
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

            EmailBlockTypeEnum::LOGO => $this->renderSiteImage('logo', $data, $text),

            EmailBlockTypeEnum::LOGO_DARK => $this->renderSiteImage('logo-dark', $data, $text),

            EmailBlockTypeEnum::FAVICON => $this->renderSiteImage('favicon', $data, $text),

            EmailBlockTypeEnum::SOCIALS => $this->renderSocials($data),
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

    /**
     * Logo, Logo Dark, and Favicon are all the same shape: one of
     * SiteConfigurationService's own image keys, read fresh at render time
     * rather than copied into the block — kSiteConfig() already resolves these
     * three to a ready-to-use URL (see setSiteConfigForCache()), so there is no
     * upload/pick step here the way there is on the plain Image block.
     */
    private function renderSiteImage(string $configKey, array $data, \Closure $text): string
    {
        $src = (string) kSiteConfig($configKey);

        if ($src === '') {
            return '';
        }

        $img = sprintf(
            '<img src="%s" alt="%s" style="display:block;width:%s;max-width:100%%;margin:0 %s;" />',
            $src,
            e((string) kSiteConfig('name')),
            $data['width'] ?? '160px',
            ($data['align'] ?? 'center') === 'center' ? 'auto' : '0',
        );

        if ($url = $text($data['link_url'] ?? '')) {
            $img = sprintf('<a href="%s" style="text-decoration:none;">%s</a>', $url, $img);
        }

        return $this->row(sprintf('<div style="text-align:%s;">%s</div>', $data['align'] ?? 'center', $img), '10px 40px');
    }

    /**
     * Either the site's own configured social handles or the block's own custom
     * link list — never both, per `$data['source']`. "image" style points at one
     * of the three PNGs public/images/socials ships per platform (plain,
     * "-white", "-black"); "text" style is a plain label link, for a source
     * (custom links) that may name a platform with no icon asset at all.
     */
    private function renderSocials(array $data): string
    {
        $links = ($data['source'] ?? 'config') === 'custom'
            ? collect($data['custom_links'] ?? [])->filter(fn (array $link) => ! empty($link['url']))
            : collect((array) kSiteConfig('social-handles', default: []))->map(fn (array $handle) => [
                'platform' => $handle['platform'],
                'label' => SocialHandleEnum::tryFrom($handle['platform'])?->label() ?? $handle['platform'],
                'url' => $handle['url'],
            ]);

        if ($links->isEmpty()) {
            return '';
        }

        $style = $data['style'] ?? 'image';
        $variant = $data['variant'] ?? 'default';

        $cells = $links->map(function (array $link) use ($style, $variant) {
            $label = e($link['label'] ?: ($link['platform'] ?? 'Link'));
            $icon = $style === 'image' ? $this->socialIcon($link['platform'] ?? '', $variant) : null;

            $inner = $icon
                ? sprintf('<img src="%s" alt="%s" width="24" height="24" style="display:block;">', $icon, $label)
                : sprintf('<span style="font-family:Arial,sans-serif;font-size:13px;font-weight:bold;color:#0F172A;">%s</span>', $label);

            return sprintf('<td style="padding:0 8px;"><a href="%s" style="text-decoration:none;">%s</a></td>', e($link['url']), $inner);
        })->implode('');

        return $this->row(sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 %s;"><tr>%s</tr></table>',
            ($data['align'] ?? 'center') === 'center' ? 'auto' : '0',
            $cells,
        ), '10px 40px');
    }

    /**
     * platform-{variant}.png under public/images/socials, or plain platform.png
     * for the "default" (coloured) variant. Blank for a custom link with no
     * recognised platform — renderSocials() falls back to a text label then.
     */
    private function socialIcon(string $platform, string $variant): ?string
    {
        $handle = SocialHandleEnum::tryFrom($platform);

        if (! $handle) {
            return null;
        }

        $suffix = in_array($variant, ['white', 'black'], true) ? "-{$variant}" : '';
        $path = "images/socials/{$handle->icon()}{$suffix}.png";

        return file_exists(public_path($path)) ? asset($path) : null;
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
     * Dispatches on `$data['layout']` — "featured" and "horizontal" stack one card
     * per row (the second with the image beside the text rather than above it),
     * "grid-2"/"grid-3" wrap into rows of that many columns. Everything else in
     * this class matches on `EmailBlockTypeEnum`; this one extra `match` exists
     * because the layout is a free-form string on the block's own data, not an enum
     * case with its own render path.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function cardsGrid(Collection $cards, array $data): string
    {
        if ($cards->isEmpty()) {
            return '';
        }

        return match ($data['layout'] ?? 'grid-3') {
            'featured' => $cards->take(1)->map(fn (array $card) => $this->featuredCard($card, $data))->implode(''),
            'horizontal' => $cards->map(fn (array $card) => $this->horizontalCard($card, $data))->implode(''),
            'grid-2' => $this->gridCards($cards, $data, 2),
            default => $this->gridCards($cards, $data, 3),
        };
    }

    /**
     * One row of up to `$columns` cards, chunked so a fifth card in a two-column
     * grid starts a new row instead of stretching the table to five thin cells.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function gridCards(Collection $cards, array $data, int $columns): string
    {
        return $cards->chunk($columns)->map(function (Collection $row) use ($data, $columns) {
            $cells = $row->map(fn (array $card) => sprintf(
                '<td valign="top" width="%d%%" style="padding:0 8px 20px;">%s</td>',
                (int) (100 / $columns),
                $this->cardBody($card, $data),
            ))->implode('');

            return sprintf('<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr>%s</tr></table>', $cells);
        })->implode('');
    }

    /**
     * A single card, full width, image above the text — the "Single featured" and
     * default grid-cell layout.
     */
    private function featuredCard(array $card, array $data): string
    {
        return sprintf('<div style="padding:0 0 10px;">%s</div>', $this->cardBody($card, $data));
    }

    /**
     * A single card with the image beside the text rather than above it — a
     * two-cell table row, image fixed at 120px so the text column has room.
     */
    private function horizontalCard(array $card, array $data): string
    {
        $showImage = $data['show_image'] ?? true;

        $image = $showImage && $card['image_url']
            ? sprintf('<td valign="top" width="120" style="padding:0 14px 20px 0;"><img src="%s" alt="" style="display:block;width:120px;border-radius:8px;" /></td>', $card['image_url'])
            : '';

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr>%s<td valign="top" style="padding:0 0 20px;">%s</td></tr></table>',
            $image,
            $this->cardText($card, $data),
        );
    }

    /**
     * Image (if enabled) stacked above title/excerpt/date/button — the shared body
     * for the featured layout and every grid cell.
     */
    private function cardBody(array $card, array $data): string
    {
        $showImage = $data['show_image'] ?? true;

        $image = $showImage && $card['image_url']
            ? sprintf('<img src="%s" alt="" style="display:block;width:100%%;border-radius:8px;margin-bottom:10px;" />', $card['image_url'])
            : '';

        return $image.$this->cardText($card, $data);
    }

    /**
     * Title, excerpt, date and the "Read more" link — the part every layout
     * renders the same way regardless of where the image sits around it.
     */
    private function cardText(array $card, array $data): string
    {
        $buttonText = e($data['button_text'] ?? 'Read More');
        $showExcerpt = $data['show_excerpt'] ?? true;
        $showDate = $data['show_date'] ?? false;

        $excerpt = $showExcerpt && $card['excerpt']
            ? sprintf('<p style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:13px;color:#64748B;line-height:1.5;">%s</p>', e($card['excerpt']))
            : '';

        $date = $showDate && $card['date']
            ? sprintf('<p style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:11px;color:#94A3B8;">%s</p>', e($card['date']))
            : '';

        return sprintf(
            '<p style="margin:0;font-family:Arial,sans-serif;font-weight:bold;font-size:13px;color:#0F172A;"><a href="%s" style="color:#0F172A;text-decoration:none;">%s</a></p>%s%s<p style="margin:10px 0 0;font-family:Arial,sans-serif;font-weight:bold;font-size:12px;"><a href="%s" style="color:#65A30D;text-decoration:none;">%s &rarr;</a></p>',
            $card['url'],
            e($card['title']),
            $excerpt,
            $date,
            $card['url'],
            $buttonText,
        );
    }

    /**
     * A row of columns, each one its own <td> and — since a column is a container
     * rather than only ever plain text — either the column's text or the image the
     * admin chose for it. A background color and/or background image can sit on
     * the row as a whole (the promo-banner case) as well as on each column.
     */
    private function renderColumns(array $data, \Closure $text): string
    {
        $columns = collect($data['columns'] ?? [])->map(function (array $column) use ($text) {
            $style = 'padding:0 12px;font-family:Arial,sans-serif;font-size:14px;color:#475569;';

            if (! empty($column['background'])) {
                $style .= 'background-color:'.$column['background'].';';
            }

            if ($bg = $this->columnBackgroundImage($column['background_image_id'] ?? null)) {
                $style .= "background-image:url({$bg});background-size:cover;background-position:center;";
            }

            $inner = ($column['type'] ?? 'text') === 'image'
                ? $this->columnImage($column, $text)
                : nl2br($text($column['text'] ?? ''));

            return sprintf('<td valign="top" style="%s">%s</td>', $style, $inner);
        })->implode('');

        $rowStyle = '';

        if (! empty($data['background'])) {
            $rowStyle .= 'background-color:'.$data['background'].';';
        }

        if ($bg = $this->columnBackgroundImage($data['background_image_id'] ?? null)) {
            $rowStyle .= "background-image:url({$bg});background-size:cover;background-position:center;";
        }

        return $this->row(sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="%s"><tr>%s</tr></table>',
            $rowStyle,
            $columns,
        ), '10px 40px');
    }

    private function columnImage(array $column, \Closure $text): string
    {
        $image = ! empty($column['image_id']) ? Image::query()->find($column['image_id']) : null;

        if (! $image) {
            return '';
        }

        return sprintf('<img src="%s" alt="%s" style="display:block;width:100%%;border-radius:4px;" />', $image->url(), e($column['alt'] ?? ''));
    }

    private function columnBackgroundImage(?int $imageId): ?string
    {
        if (! $imageId) {
            return null;
        }

        return Image::query()->find($imageId)?->url();
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
    private function row(string $inner, string $padding, string $height = '', ?string $background = null): string
    {
        $style = "padding:{$padding};"
            .($height !== '' ? "height:{$height};line-height:{$height};font-size:1px;" : '')
            .($background !== null ? "background:{$background};" : '');

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
        $radius = (int) ($design['container_radius'] ?? 6);
        $fontFamily = $this->fontStack($design['font_family'] ?? 'sans');
        $accentBar = $this->accentBar($design);

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
        <table role="presentation" width="{$width}" cellpadding="0" cellspacing="0" style="width:{$width}px;max-width:100%;background:{$containerBackground};border-radius:{$radius}px;overflow:hidden;">
        {$accentBar}
        {$body}
        </table>
        </td></tr>
        </table>
        </body>
        </html>
        HTML;
    }

    /**
     * The short typeface key the Design dropdown saves ("sans"/"serif"/"mono")
     * resolved to an actual email-safe font stack. Anything else unrecognised
     * falls back to sans rather than being written into the `style=` attribute
     * verbatim — a raw, un-vetted string there is a CSS-injection into an HTML
     * email client's DOM, not just a bad font.
     */
    private function fontStack(string $font): string
    {
        return match ($font) {
            'serif' => 'Georgia, "Times New Roman", serif',
            'mono' => '"Courier New", Courier, monospace',
            default => 'Arial, Helvetica, sans-serif',
        };
    }

    /**
     * A brand-coloured rule across the top of the letter, one table row, the same
     * "component" every other block type gets its own render method for — the
     * Design dropdown's `accent_bar` switch is the only thing that turns it on.
     */
    private function accentBar(array $design): string
    {
        if (empty($design['accent_bar'])) {
            return '';
        }

        return $this->row('&nbsp;', '0', '6px', $design['brand'] ?? '#65A30D');
    }
}
