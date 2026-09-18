<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The kinds of block the email builder's canvas can hold. Stored as the "type" key of
 * each entry in an EmailTemplate/EmailCampaign's `content` array — see
 * EmailRenderService::renderBlocks() for what each case renders as.
 */
enum EmailBlockTypeEnum: string
{
    use WithEnumHelpers;

    case HEADING = 'heading';
    case PARAGRAPH = 'paragraph';
    case BUTTON = 'button';
    case DIVIDER = 'divider';
    case SPACER = 'spacer';
    case IMAGE = 'image';

    // Raw HTML, sanitised on render the same way BlogService::sanitize() protects a
    // post body — an "advanced" block, deliberately last among the basics.
    case HTML = 'html';

    // A single reference into a DynamicContentProvider — "the latest post", "post
    // #214" — resolved at render time rather than copied in.
    case DYNAMIC_CONTENT = 'dynamic_content';

    // "You may also like" — related items for whichever dynamic content block sits
    // above it, or a source of its own when there is none.
    case RELATED_CONTENT = 'related_content';

    case COLUMNS = 'columns';

    // A reference to a saved EmailSection (header, footer, CTA, promo, custom).
    case SECTION = 'section';

    // Site Config group — each reads straight from kSiteConfig() at render time
    // rather than copying a value in, so a logo or social handle changed after
    // this block was placed reaches the email without anybody reopening it.
    case LOGO = 'logo';

    case LOGO_DARK = 'logo_dark';

    case FAVICON = 'favicon';

    case SOCIALS = 'socials';

    public function isHeading(): bool
    {
        return $this === self::HEADING;
    }

    public function isParagraph(): bool
    {
        return $this === self::PARAGRAPH;
    }

    public function isButton(): bool
    {
        return $this === self::BUTTON;
    }

    public function isDivider(): bool
    {
        return $this === self::DIVIDER;
    }

    public function isSpacer(): bool
    {
        return $this === self::SPACER;
    }

    public function isImage(): bool
    {
        return $this === self::IMAGE;
    }

    public function isHtml(): bool
    {
        return $this === self::HTML;
    }

    public function isDynamicContent(): bool
    {
        return $this === self::DYNAMIC_CONTENT;
    }

    public function isRelatedContent(): bool
    {
        return $this === self::RELATED_CONTENT;
    }

    public function isColumns(): bool
    {
        return $this === self::COLUMNS;
    }

    public function isSection(): bool
    {
        return $this === self::SECTION;
    }

    public function isLogo(): bool
    {
        return $this === self::LOGO;
    }

    public function isLogoDark(): bool
    {
        return $this === self::LOGO_DARK;
    }

    public function isFavicon(): bool
    {
        return $this === self::FAVICON;
    }

    public function isSocials(): bool
    {
        return $this === self::SOCIALS;
    }

    /**
     * The cases a Columns block's own appender offers per column — Basic and Site
     * Config, everything a column can hold as a child. Columns and Section are
     * deliberately excluded: a column holding another Columns block has no email
     * client that renders nested tables sanely, and a Section already carries
     * whatever blocks it needs without wrapping it in one more container. Dynamic
     * Content and Related Content stay top-level too — a card grid inside a
     * narrow column reads as a crushed accident, not a deliberate layout.
     *
     * @return array<int, self>
     */
    public static function nestable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => ! in_array($case, [
            self::COLUMNS, self::SECTION, self::DYNAMIC_CONTENT, self::RELATED_CONTENT,
        ], true)));
    }

    /**
     * The block palette's grouping — see resources/views/components/marketing/blocks.
     */
    public function group(): string
    {
        return match ($this) {
            self::HEADING, self::PARAGRAPH, self::BUTTON, self::DIVIDER, self::SPACER, self::IMAGE, self::HTML => 'Basic',
            self::DYNAMIC_CONTENT, self::RELATED_CONTENT => 'Dynamic Content',
            self::COLUMNS => 'Layout',
            self::LOGO, self::LOGO_DARK, self::FAVICON, self::SOCIALS => 'Site Config',
            self::SECTION => 'Saved',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HEADING => 'bars-3-bottom-left',
            self::PARAGRAPH => 'bars-3',
            self::BUTTON => 'cursor-arrow-rays',
            self::DIVIDER => 'minus',
            self::SPACER => 'arrows-up-down',
            self::IMAGE => 'photo',
            self::HTML => 'code-bracket',
            self::DYNAMIC_CONTENT => 'newspaper',
            self::RELATED_CONTENT => 'squares-2x2',
            self::COLUMNS => 'view-columns',
            self::SECTION => 'rectangle-stack',
            self::LOGO, self::LOGO_DARK => 'photo',
            self::FAVICON => 'star',
            self::SOCIALS => 'share',
        };
    }

    /**
     * The data shape a fresh block of this type starts with.
     *
     * @return array<string, mixed>
     */
    public function defaultData(): array
    {
        return match ($this) {
            self::HEADING => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::TEXT->value => 'New heading',
                EmailBlockTypeElementEnum::LEVEL,
                EmailBlockTypeElementEnum::ALIGN,
                EmailBlockTypeElementEnum::COLOR,
                EmailBlockTypeElementEnum::CHAR_CASE,
                EmailBlockTypeElementEnum::FONT,

                // Layout
                EmailBlockTypeElementEnum::BORDER,
                EmailBlockTypeElementEnum::RADIUS,
                EmailBlockTypeElementEnum::BACKGROUND,
                EmailBlockTypeElementEnum::SPACING,
                EmailBlockTypeElementEnum::BORDER_SPACING,
            ]),
            self::PARAGRAPH => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::TEXT->value => 'New paragraph text.',
                EmailBlockTypeElementEnum::ALIGN->value => 'left',
                EmailBlockTypeElementEnum::COLOR->value => '#475569',
                EmailBlockTypeElementEnum::CHAR_CASE,
                EmailBlockTypeElementEnum::FONT,
                EmailBlockTypeElementEnum::FONT_SIZE,

                // Layout
                EmailBlockTypeElementEnum::BORDER,
                EmailBlockTypeElementEnum::RADIUS,
                EmailBlockTypeElementEnum::BACKGROUND,
                EmailBlockTypeElementEnum::SPACING,
                EmailBlockTypeElementEnum::BORDER_SPACING,
            ]),
            self::BUTTON => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::TEXT->value => 'Click here',
                EmailBlockTypeElementEnum::URL,
                EmailBlockTypeElementEnum::COLOR->value => '#0F172A',
                EmailBlockTypeElementEnum::CHAR_CASE,
                EmailBlockTypeElementEnum::BUTTON,
                EmailBlockTypeElementEnum::FONT_WEIGHT,

                // Layout
                EmailBlockTypeElementEnum::ALIGN,
                EmailBlockTypeElementEnum::BACKGROUND->value => '#A3E635',
                EmailBlockTypeElementEnum::SPACING,
            ]),
            self::DIVIDER => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::COLOR->value => '#E2E8F0',
                EmailBlockTypeElementEnum::WIDTH,
                EmailBlockTypeElementEnum::ITEM_MOVE,

                // Layout
                EmailBlockTypeElementEnum::SPACING->value => 5,
            ]),
            self::SPACER => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::HEIGHT,
            ]),
            self::IMAGE => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::IMAGE_ID,
                EmailBlockTypeElementEnum::ALT,
                EmailBlockTypeElementEnum::LINK_URL,
                EmailBlockTypeElementEnum::WIDTH,
                EmailBlockTypeElementEnum::ITEM_MOVE,
                EmailBlockTypeElementEnum::RADIUS,

                // Layout
                EmailBlockTypeElementEnum::SPACING,
            ]),
            self::HTML => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::HTML,
                EmailBlockTypeElementEnum::SPACING,
            ]),
            self::DYNAMIC_CONTENT => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::CONTENT_TYPE,
                EmailBlockTypeElementEnum::MODE,
                EmailBlockTypeElementEnum::CONTENT_ID,
                EmailBlockTypeElementEnum::CATEGORY_ID,
                EmailBlockTypeElementEnum::TAG_ID,
                EmailBlockTypeElementEnum::LIMIT,
                EmailBlockTypeElementEnum::LAYOUT,
                EmailBlockTypeElementEnum::SHOW_IMAGE,
                EmailBlockTypeElementEnum::SHOW_EXCERPT,
                EmailBlockTypeElementEnum::SHOW_DATE,
                EmailBlockTypeElementEnum::BUTTON_TEXT,
                EmailBlockTypeElementEnum::SPACING->value => 30,
            ]),
            self::RELATED_CONTENT => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::CONTENT_TYPE,
                EmailBlockTypeElementEnum::SOURCE->value => 'same_category',
                EmailBlockTypeElementEnum::SOURCE_CONTENT_ID,
                EmailBlockTypeElementEnum::LIMIT->value => 3,
                EmailBlockTypeElementEnum::HEADING,
                EmailBlockTypeElementEnum::BUTTON_TEXT,
                EmailBlockTypeElementEnum::SPACING->value => 34,
            ]),
            self::COLUMNS => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::BACKGROUND,
                EmailBlockTypeElementEnum::BACKGROUND_IMAGE_ID,
                EmailBlockTypeElementEnum::SPACING,
                EmailBlockTypeElementEnum::COLUMNS->value => [
                    EmailBlockTypeElementEnum::make([
                        EmailBlockTypeElementEnum::BACKGROUND,
                        EmailBlockTypeElementEnum::BACKGROUND_IMAGE_ID,
                        EmailBlockTypeElementEnum::BLOCKS,
                    ]),
                    EmailBlockTypeElementEnum::make([
                        EmailBlockTypeElementEnum::BACKGROUND,
                        EmailBlockTypeElementEnum::BACKGROUND_IMAGE_ID,
                        EmailBlockTypeElementEnum::BLOCKS,
                    ]),
                ],
            ]),
            self::SECTION => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::EMAIL_SECTION_ID,
            ]),
            self::LOGO, self::LOGO_DARK => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::WIDTH->value => '160px',
                EmailBlockTypeElementEnum::ALIGN,
                EmailBlockTypeElementEnum::LINK_URL->value => '{{site.url}}',
                EmailBlockTypeElementEnum::SPACING,
            ]),
            self::FAVICON => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::WIDTH->value => '32px',
                EmailBlockTypeElementEnum::ALIGN,
                EmailBlockTypeElementEnum::SPACING,
            ]),
            self::SOCIALS => EmailBlockTypeElementEnum::make([
                EmailBlockTypeElementEnum::SOURCE,
                EmailBlockTypeElementEnum::STYLE,
                EmailBlockTypeElementEnum::VARIANT,
                EmailBlockTypeElementEnum::ALIGN,
                EmailBlockTypeElementEnum::CUSTOM_LINKS,
                EmailBlockTypeElementEnum::SPACING,
            ]),
        };
    }
}
