<?php

namespace App\Enums;

use App\Services\EmailBlockItemService;
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
        $itemService = app(EmailBlockItemService::class);

        return match ($this) {
            self::HEADING => $itemService->make([
                EmailBlockItemEnum::TEXT->value => 'New heading',
                EmailBlockItemEnum::LEVEL,
                EmailBlockItemEnum::ALIGN,
                EmailBlockItemEnum::COLOR,
                EmailBlockItemEnum::CHAR_CASE,
                EmailBlockItemEnum::FONT,
            ], allLayout: true),
            self::PARAGRAPH => $itemService->make([
                EmailBlockItemEnum::TEXT->value => 'New paragraph text.',
                EmailBlockItemEnum::ALIGN->value => 'left',
                EmailBlockItemEnum::COLOR->value => '#475569',
                EmailBlockItemEnum::CHAR_CASE,
                EmailBlockItemEnum::FONT,
                EmailBlockItemEnum::FONT_SIZE,
            ], allLayout: true),
            self::BUTTON => $itemService->make([
                EmailBlockItemEnum::TEXT->value => 'Click here',
                EmailBlockItemEnum::URL,
                EmailBlockItemEnum::COLOR->value => '#0F172A',
                EmailBlockItemEnum::WIDTH,
                EmailBlockItemEnum::ITEM_BACKGROUND->value => '#A3E635',
                EmailBlockItemEnum::CHAR_CASE,
                EmailBlockItemEnum::FONT_WEIGHT,
                EmailBlockItemEnum::ITEM_RADIUS,
                EmailBlockItemEnum::ITEM_SPACING,
                EmailBlockItemEnum::BORDER_WIDTH->value => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 5],
                EmailBlockItemEnum::BORDER_COLOR,
                EmailBlockItemEnum::ELEMENT_DISPLAY,

                // Parent
                EmailBlockItemEnum::PARENT_ALIGN,

                // Layout
                EmailBlockItemEnum::SPACING,
            ]),
            self::DIVIDER => $itemService->make([
                EmailBlockItemEnum::COLOR->value => '#E2E8F0',
                EmailBlockItemEnum::WIDTH,
                EmailBlockItemEnum::ITEM_MOVE,
                EmailBlockItemEnum::HEIGHT->value => 2,

                // Layout
                EmailBlockItemEnum::SPACING->value => ['top' => 5, 'left' => 20, 'bottom' => 5, 'right' => 20],
            ]),
            self::SPACER => $itemService->make([
                EmailBlockItemEnum::HEIGHT,
            ]),
            self::IMAGE => $itemService->make([
                EmailBlockItemEnum::IMAGE_ID,
                EmailBlockItemEnum::ALT,
                EmailBlockItemEnum::LINK_URL,
                EmailBlockItemEnum::WIDTH,
                EmailBlockItemEnum::ITEM_MOVE,
                EmailBlockItemEnum::ITEM_RADIUS,

                // Layout
                EmailBlockItemEnum::SPACING,
            ]),
            self::HTML => $itemService->make([
                EmailBlockItemEnum::HTML,
                EmailBlockItemEnum::SPACING,
            ]),
            self::DYNAMIC_CONTENT => $itemService->make([
                EmailBlockItemEnum::CONTENT_TYPE,
                EmailBlockItemEnum::MODE,
                EmailBlockItemEnum::CONTENT_ID,
                EmailBlockItemEnum::CATEGORY_ID,
                EmailBlockItemEnum::TAG_ID,
                EmailBlockItemEnum::LIMIT,
                EmailBlockItemEnum::LAYOUT,
                EmailBlockItemEnum::SHOW_IMAGE,
                EmailBlockItemEnum::SHOW_EXCERPT,
                EmailBlockItemEnum::SHOW_DATE,
                EmailBlockItemEnum::BUTTON_TEXT,

                // Layout
                EmailBlockItemEnum::SPACING->value => 30,
            ]),
            self::RELATED_CONTENT => $itemService->make([
                EmailBlockItemEnum::CONTENT_TYPE,
                EmailBlockItemEnum::SOURCE->value => 'same_category',
                EmailBlockItemEnum::SOURCE_CONTENT_ID,
                EmailBlockItemEnum::LIMIT->value => 3,
                EmailBlockItemEnum::HEADING,
                EmailBlockItemEnum::BUTTON_TEXT,

                // Layout
                EmailBlockItemEnum::SPACING->value => 34,
            ]),
            self::COLUMNS => $itemService->make([
                EmailBlockItemEnum::BACKGROUND,
                EmailBlockItemEnum::BACKGROUND_IMAGE_ID,
                EmailBlockItemEnum::SPACING,
                EmailBlockItemEnum::COLUMNS->value => [
                    $itemService->make([
                        EmailBlockItemEnum::BACKGROUND,
                        EmailBlockItemEnum::BACKGROUND_IMAGE_ID,
                        EmailBlockItemEnum::BLOCKS,
                    ]),
                    $itemService->make([
                        EmailBlockItemEnum::BACKGROUND,
                        EmailBlockItemEnum::BACKGROUND_IMAGE_ID,
                        EmailBlockItemEnum::BLOCKS,
                    ]),
                ],
            ]),
            self::SECTION => $itemService->make([
                EmailBlockItemEnum::EMAIL_SECTION_ID,
            ]),
            self::LOGO,
            self::LOGO_DARK => $itemService->make([
                EmailBlockItemEnum::WIDTH->value => 'sm',
                EmailBlockItemEnum::LINK_URL->value => '{{site.url}}',
                EmailBlockItemEnum::ITEM_MOVE,

                // Layout
                EmailBlockItemEnum::SPACING,
            ]),
            self::FAVICON => $itemService->make([
                EmailBlockItemEnum::WIDTH->value => 'xs',
                EmailBlockItemEnum::ITEM_MOVE,

                // Layout
                EmailBlockItemEnum::SPACING,
            ]),
            self::SOCIALS => $itemService->make([
                EmailBlockItemEnum::SOURCE,
                EmailBlockItemEnum::STYLE,
                EmailBlockItemEnum::VARIANT,
                EmailBlockItemEnum::CUSTOM_LINKS,
                EmailBlockItemEnum::ELEMENT_DISPLAY,

                // Image
                EmailBlockItemEnum::WIDTH_VALUE->value => 40,
                EmailBlockItemEnum::HEIGHT->value => 40,

                // For Name Only
                EmailBlockItemEnum::COLOR->value => '#0F172A',
                EmailBlockItemEnum::CHAR_CASE,
                EmailBlockItemEnum::FONT,
                EmailBlockItemEnum::FONT_SIZE,
                EmailBlockItemEnum::FONT_WEIGHT,
                EmailBlockItemEnum::ITEM_BACKGROUND->value => '#f1f5f9',
                EmailBlockItemEnum::ITEM_RADIUS,
                EmailBlockItemEnum::ITEM_SPACING->value => ['x' => 10, 'y' => 5],

                // Parent
                EmailBlockItemEnum::PARENT_ALIGN,
            ], allLayout: true),
        };
    }

    /**
     * Whether this block type rejects the default container CSS applied to most
     * blocks in EmailRenderService::renderBlocks(). Buttons, dividers and images
     * are already self-contained, so they don't need the extra padding and border.
     */
    public function rejectMostContainerCss()
    {
        return \in_array($this, [
            self::DYNAMIC_CONTENT,
            self::RELATED_CONTENT,
            self::COLUMNS,
            self::SECTION,
            self::BUTTON,
            self::LOGO,
            self::LOGO_DARK,
            self::FAVICON,
            self::SOCIALS,
            self::DIVIDER,
            self::IMAGE,
        ], true);
    }

    /**
     * Whether this block type has no layout components.
     */
    public function skipLayout()
    {
        return \in_array($this, [
            self::SECTION,
            self::SPACER,
        ], true);
    }
}
