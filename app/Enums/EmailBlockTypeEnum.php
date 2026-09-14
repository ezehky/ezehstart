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

    /**
     * The block palette's grouping — see resources/views/components/marketing/blocks.
     */
    public function group(): string
    {
        return match ($this) {
            self::HEADING, self::PARAGRAPH, self::BUTTON, self::DIVIDER, self::SPACER, self::IMAGE, self::HTML => 'Basic',
            self::DYNAMIC_CONTENT, self::RELATED_CONTENT => 'Dynamic Content',
            self::COLUMNS => 'Layout',
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
            self::HEADING => ['text' => 'New heading', 'level' => 'h1', 'align' => 'center', 'color' => '#0F172A'],
            self::PARAGRAPH => ['text' => 'New paragraph text.', 'align' => 'left', 'color' => '#475569'],
            self::BUTTON => ['text' => 'Click here', 'url' => '', 'align' => 'center', 'background' => '#A3E635', 'color' => '#0F172A', 'new_tab' => false],
            self::DIVIDER => ['color' => '#E2E8F0'],
            self::SPACER => ['height' => 24],
            self::IMAGE => ['image_id' => null, 'alt' => '', 'link_url' => '', 'width' => '100%', 'align' => 'center', 'radius' => 8],
            self::HTML => ['html' => ''],
            self::DYNAMIC_CONTENT => ['content_type' => 'post', 'mode' => 'latest', 'content_id' => null, 'category_id' => null, 'tag_id' => null, 'limit' => 1, 'layout' => 'featured', 'show_image' => true, 'show_excerpt' => true, 'show_date' => false, 'button_text' => 'Read More'],
            self::RELATED_CONTENT => ['content_type' => 'post', 'source' => 'same_category', 'source_content_id' => null, 'limit' => 3, 'heading' => 'You May Also Like', 'button_text' => 'Read More'],
            self::COLUMNS => ['columns' => [['text' => 'First column'], ['text' => 'Second column']]],
            self::SECTION => ['email_section_id' => null],
        };
    }
}
