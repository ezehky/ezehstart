<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum EmailBlockItemEnum: string
{
    use WithEnumHelpers;

    case TEXT = 'text';
    case LEVEL = 'level';
    case ALIGN = 'align';
    case COLOR = 'color';
    case SPACING = 'spacing';
    case BORDER_SPACING = 'border_spacing';
    case CHAR_CASE = 'char_case';
    case FONT = 'font';
    case FONT_SIZE = 'font_size';
    case FONT_WEIGHT = 'font_weight';
    case BORDER = 'border';
    case RADIUS = 'radius';

    /* BUTTON Specifics */
    case URL = 'url';
    /* BUTTON Specifics */

    case BORDER_WIDTH = 'border_width';
    case BORDER_COLOR = 'border_color';

    // HTML ELEMENT DISPLAY
    case ELEMENT_DISPLAY = 'element_display';

    // ITEM Specifics
    case ITEM_BACKGROUND = 'item_background';
    case ITEM_MOVE = 'item_move';
    case ITEM_SPACING = 'item_spacing';
    case ITEM_RADIUS = 'item_radius';

    // Parent Specifics
    case PARENT_ALIGN = 'parent_align';

    case BACKGROUND = 'background';

    case HEIGHT = 'height';
    case WIDTH = 'width';
    case WIDTH_VALUE = 'width_value';

    case IMAGE_ID = 'image_id';
    case ALT = 'alt';
    case LINK_URL = 'link_url';

    case HTML = 'html';

    case CONTENT_TYPE = 'content_type';
    case MODE = 'mode';
    case CONTENT_ID = 'content_id';
    case CATEGORY_ID = 'category_id';
    case TAG_ID = 'tag_id';
    case LIMIT = 'limit';
    case LAYOUT = 'layout';
    case SHOW_IMAGE = 'show_image';
    case SHOW_EXCERPT = 'show_excerpt';
    case SHOW_DATE = 'show_date';
    case BUTTON_TEXT = 'button_text';
    case HEADING = 'heading';

    case SOURCE = 'source';
    case SOURCE_CONTENT_ID = 'source_content_id';

    case COLUMNS = 'columns';
    case BACKGROUND_IMAGE_ID = 'background_image_id';
    case BLOCKS = 'blocks';

    case EMAIL_SECTION_ID = 'email_section_id';

    case STYLE = 'style';
    case VARIANT = 'variant';
    case CUSTOM_LINKS = 'custom_links';

    // ===========================================================================
    // Checkers
    // ===========================================================================
    public function isSpacing(): bool
    {
        return $this === self::SPACING;
    }

    public function isBorderSpacing(): bool
    {
        return $this === self::BORDER_SPACING;
    }

    public function isBorder(): bool
    {
        return $this === self::BORDER;
    }

    public function isItemMove(): bool
    {
        return $this === self::ITEM_MOVE;
    }

    public function isItemSpacing(): bool
    {
        return $this === self::ITEM_SPACING;
    }

    public function isBorderWidth(): bool
    {
        return $this === self::BORDER_WIDTH;
    }

    public function isWidth(): bool
    {
        return $this === self::WIDTH;
    }

    public function isBackgroundImage(): bool
    {
        return $this === self::BACKGROUND_IMAGE_ID;
    }

    // // ===========================================================================
    // // Checkers
    // // ===========================================================================

    public function isLayoutItem()
    {
        return \in_array(
            $this,
            [
                self::BORDER,
                self::RADIUS,
                self::BACKGROUND,
                self::BACKGROUND_IMAGE_ID,
                self::SPACING,
                self::BORDER_SPACING,
            ],
            true
        );
    }

    public function isParentItem(): bool
    {
        return \in_array(
            $this,
            [
                self::PARENT_ALIGN,
            ],
            true
        );
    }
}
