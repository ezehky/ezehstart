<?php

namespace App\Services;

use App\Enums\EmailBlockItemEnum;
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

        // Spacer and Section don't carry the type's usual CSS shape — a spacer
        // is nothing but its own height, and a section is a reference whose
        // *own* blocks already carry whatever CSS they need — so both skip the
        // getCss()/wrap() path below entirely.
        if ($type->isSpacer()) {
            return $this->row('&nbsp;', '', ((int) ($data['height'] ?? 24)).'px');
        }

        if ($type->isSection()) {
            return $this->renderSectionReference($data, $recipient);
        }

        $itemService = app(EmailBlockItemService::class);
        $variables = app(EmailVariableService::class);
        $css = $itemService->getCss($data, $type);
        $text = fn (?string $value) => e($variables->resolve($value, recipient: $recipient));

        $inner = match ($type) {
            EmailBlockTypeEnum::HEADING => $this->renderHeading($data, $css, $text),
            EmailBlockTypeEnum::PARAGRAPH => $this->renderParagraph($data, $css, $variables, $recipient),
            EmailBlockTypeEnum::BUTTON => $this->renderButton($data, $css, $text),
            EmailBlockTypeEnum::DIVIDER => sprintf('<hr style="%s">', $css['style']),
            EmailBlockTypeEnum::IMAGE => $this->renderImage($data, $css, $text),
            EmailBlockTypeEnum::HTML => $this->sanitize($variables->resolve($data['html'] ?? '', recipient: $recipient, html: true)),
            EmailBlockTypeEnum::DYNAMIC_CONTENT => $this->renderDynamicContent($data),
            EmailBlockTypeEnum::RELATED_CONTENT => $this->renderRelatedContent($data),
            EmailBlockTypeEnum::COLUMNS => $this->renderColumns($data, $css, $recipient),
            EmailBlockTypeEnum::LOGO => $this->renderSiteImage('logo', $data, $css, $text),
            EmailBlockTypeEnum::LOGO_DARK => $this->renderSiteImage('logo-dark', $data, $css, $text),
            EmailBlockTypeEnum::FAVICON => $this->renderSiteImage('favicon', $data, $css, $text),
            EmailBlockTypeEnum::SOCIALS => $this->renderSocials($data, $css),
            EmailBlockTypeEnum::SPACER, EmailBlockTypeEnum::SECTION => '', // handled above, unreachable
        };

        if ($inner === '') {
            return '';
        }

        // A block that rejects most container CSS (see
        // EmailBlockTypeEnum::rejectMostContainerCss()) already builds its own
        // background/border/radius into its inner markup — via getCss()'s
        // 'style'/'container' split applied one level down (a column's own
        // <td>, a button's own <a>) — so the row wrapping it only ever needs
        // the plain top/bottom clearance from its 'spacing' field, never the
        // full container CSS a second time.
        if ($type->rejectMostContainerCss()) {
            $padding = $itemService->resolveSpacing(EmailBlockItemEnum::SPACING, data_get($data, 'spacing'), style: true);

            return $this->row($inner, $padding);
        }

        return $this->row($inner, $css['container']);
    }

    /**
     * The heading block's `<h1>`–`<h6>` tag. Everything about how it looks —
     * color, alignment, case, font, and the level's own font-size/line-height —
     * is already folded into $css['style'] by EmailBlockItemService::getCss(),
     * the same string the admin's canvas preview renders with.
     */
    private function renderHeading(array $data, array $css, \Closure $text): string
    {
        $level = app(EmailBlockItemService::class)->default(EmailBlockItemEnum::LEVEL, data_get($data, 'level'));

        return sprintf(
            '<%1$s style="margin:0;%2$s">%3$s</%1$s>',
            $level,
            $css['style'],
            $text(data_get($data, 'text', '')),
        );
    }

    /**
     * Trusted rich text, sanitized and resolved for variables the same way the
     * HTML block is — $css['style'] carries alignment, color, font and case.
     */
    private function renderParagraph(array $data, array $css, EmailVariableService $variables, ?User $recipient): string
    {
        return sprintf(
            '<div style="margin:0;%s">%s</div>',
            $css['style'],
            $this->sanitize($variables->resolve($data['text'] ?? '', recipient: $recipient, html: true)),
        );
    }

    /**
     * $css['parent'] (from PARENT_ALIGN) places the button within its row;
     * $css['style'] is everything the settings panel offers on the button
     * itself — background, border, radius, width, padding, font, case — baked
     * in by getCss() rather than assembled by hand here.
     */
    private function renderButton(array $data, array $css, \Closure $text): string
    {
        $url = $text($data['url'] ?? '#') ?: '#';
        $label = $text($data['text'] ?? 'Click here');

        return sprintf(
            '<div style="%s"><a href="%s" style="%s">%s</a></div>',
            $css['parent'],
            $url,
            $css['style'],
            $label,
        );
    }

    private function renderImage(array $data, array $css, \Closure $text): string
    {
        $image = ! empty($data['image_id']) ? Image::query()->find($data['image_id']) : null;

        if (! $image) {
            return '';
        }

        $img = sprintf(
            '<img src="%s" alt="%s" style="%s" />',
            $image->url(),
            e($data['alt'] ?? ''),
            $css['style'],
        );

        if ($url = $text($data['link_url'] ?? '')) {
            $img = sprintf('<a href="%s" style="text-decoration:none;">%s</a>', $url, $img);
        }

        return $img;
    }

    /**
     * Logo, Logo Dark, and Favicon are all the same shape: one of
     * SiteConfigurationService's own image keys, read fresh at render time
     * rather than copied into the block — kSiteConfig() already resolves these
     * three to a ready-to-use URL (see setSiteConfigForCache()), so there is no
     * upload/pick step here the way there is on the plain Image block. Sizing
     * and placement come from $css['style'] (WIDTH, ITEM_MOVE) exactly like
     * the plain Image block.
     */
    private function renderSiteImage(string $configKey, array $data, array $css, \Closure $text): string
    {
        $src = (string) kSiteConfig($configKey);

        if ($src === '') {
            return '';
        }

        $img = sprintf(
            '<img src="%s" alt="%s" style="%s" />',
            $src,
            e((string) kSiteConfig('name')),
            $css['style'],
        );

        if ($url = $text($data['link_url'] ?? '')) {
            $img = sprintf('<a href="%s" style="text-decoration:none;">%s</a>', $url, $img);
        }

        return $img;
    }

    /**
     * Either the site's own configured social handles or the block's own custom
     * link list — never both, per `$data['source']`. "image" style points at one
     * of the three PNGs public/images/socials ships per platform (plain,
     * "-white", "-black"); "text" style is a plain label link, for a source
     * (custom links) that may name a platform with no icon asset at all.
     * `socialsIrrelevantFields()` already stripped whichever set of $css['style']
     * doesn't apply to the active style before this runs (see
     * EmailBlockItemService::getCss()), so the same style string is correct for
     * every link regardless of icon vs. text.
     */
    private function renderSocials(array $data, array $css): string
    {
        $links = $this->socialLinks($data);

        if ($links->isEmpty()) {
            return '';
        }

        $style = $data['style'] ?? 'image';
        $variant = $data['variant'] ?? 'default';

        $items = $links->map(function (array $link) use ($style, $variant, $css) {
            $label = e($link['label'] ?: ($link['platform'] ?? 'Link'));
            $icon = $style === 'image' ? $this->socialIcon($link['platform'] ?? '', $variant) : null;

            $inner = $icon
                ? sprintf('<img src="%s" alt="%s" style="display:inline-block;vertical-align:middle;%s" />', $icon, $label, $css['style'])
                : sprintf('<span style="display:inline-block;%s">%s</span>', $css['style'], $label);

            return sprintf('<a href="%s" style="text-decoration:none;display:inline-block;margin:0 4px;">%s</a>', e($link['url']), $inner);
        })->implode('');

        return sprintf('<div style="%s">%s</div>', $css['parent'], $items);
    }

    /**
     * @return Collection<int, array{platform: ?string, label: string, url: string}>
     */
    private function socialLinks(array $data): Collection
    {
        return ($data['source'] ?? 'config') === 'custom'
            ? collect($data['custom_links'] ?? [])->filter(fn (array $link) => ! empty($link['url']))
            : collect((array) kSiteConfig('social-handles', default: []))->map(fn (array $handle) => [
                'platform' => $handle['platform'],
                'label' => SocialHandleEnum::tryFrom($handle['platform'])?->label() ?? $handle['platform'],
                'url' => $handle['url'],
            ]);
    }

    /**
     * platform-{variant}.png under public/images/socials, or plain platform.png
     * for the "default" (coloured) variant. Blank for a custom link with no
     * recognised platform — renderSocials() falls back to a text label then.
     */
    public function socialIcon(string $platform, string $variant): ?string
    {
        $handle = SocialHandleEnum::tryFrom($platform);

        if (! $handle) {
            return null;
        }

        $suffix = \in_array($variant, ['white', 'black'], true) ? "-{$variant}" : '';
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

        return $this->cardsGrid($cards, $data);
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

        return $heading.$this->cardsGrid($cards, $data);
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
     * A row of columns, each one its own <td> holding its own nested <table> of
     * whatever blocks the admin put in that column. A column carries no 'type' of
     * its own, so its CSS is computed the same way WithBlockEditor::cssForBlock()
     * stamps it for the canvas — getCss() against the column's own data with a
     * null type — and lands straight on that column's <td>; the row's own
     * background/border/radius (from the Columns block's own data) lands on the
     * inner grid <table> instead of the outer <tr>, per rejectMostContainerCss().
     */
    private function renderColumns(array $data, array $css, ?User $recipient): string
    {
        $itemService = app(EmailBlockItemService::class);

        $columns = collect($data['columns'] ?? [])->map(function (array $column) use ($recipient, $itemService) {
            $columnData = $column['data'] ?? $column;
            $columnCss = $itemService->getCss($columnData, null);

            $inner = sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0">%s</table>',
                $this->renderBlocks($columnData['blocks'] ?? [], $recipient),
            );

            return sprintf(
                '<td valign="top" style="font-family:Arial,sans-serif;font-size:14px;color:#475569;%s">%s</td>',
                $columnCss['container'],
                $inner,
            );
        })->implode('');

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="%s"><tr>%s</tr></table>',
            $css['container'],
            $columns,
        );
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
     * One block, wrapped in the table row every email-safe block needs. $style is
     * a complete inline-style string — the block's own $css['container'] or
     * resolved spacing — never assembled from separate padding/background parts
     * here, so it stays a plain pass-through onto the wrapping <td>.
     */
    private function row(string $inner, string $style = '', string $height = ''): string
    {
        if ($height !== '') {
            $style .= "height:{$height};line-height:{$height};font-size:1px;";
        }

        // Check style has margin
        if (str_contains(strtolower($style), 'margin')) {
            return sprintf('<tr><td><div style="%s">%s</div></td></tr>', $style, $inner);
        }

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
        $designCss = app(EmailBlockItemService::class)->getCss($design, isDesign: true);
        $accentBar = $this->accentBar($design);

        return <<<HTML
        <!doctype html>
        <html>
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="{$designCss['container']}">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" cellpadding="0" cellspacing="0" style="{$designCss['style']}">
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

        return $this->row('&nbsp;', 'background:'.($design['brand'] ?? '#65A30D').';', '6px');
    }
}
