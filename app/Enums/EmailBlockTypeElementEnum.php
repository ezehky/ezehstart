<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum EmailBlockTypeElementEnum: string
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
    case BUTTON = 'button';
    /* BUTTON Specifics */

    case BACKGROUND = 'background';

    case HEIGHT = 'height';
    case WIDTH = 'width';
    case ITEM_MOVE = 'item_move';

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

    public function isButton(): bool
    {
        return $this === self::BUTTON;
    }

    // ===========================================================================
    // Checkers
    // ===========================================================================
    /**
     * Returns the default value for the enum case.
     *
     * @param  mixed  $alternative  An alternative value to return instead of the default.
     * @return mixed The default value for the enum case or the alternative value if provided.
     */
    public function default(mixed $alternative = null): mixed
    {
        return $alternative !== null ? $alternative : match ($this) {
            self::TEXT => 'New text',
            self::LEVEL => 'h1',
            self::ALIGN => 'center',
            self::COLOR => '#0F172A',
            self::CHAR_CASE => 'normal',
            self::FONT => 'arial',
            self::FONT_SIZE => 'sm',
            self::FONT_WEIGHT => 'normal',
            self::BORDER => ['width' => 0, 'style' => 'solid', 'color' => '#A3E635'],
            self::RADIUS => 'none',
            self::SPACING => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            self::BORDER_SPACING => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],

            // BUTTON Specifics
            self::URL => '',
            self::BUTTON => [
                'background' => '#A3E635',
                'new_tab' => false,
                'full_width' => false,
                'border_width' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 5],
                'border_color' => '#0F172A',
                'border_radius' => 'rounded',
                'spacing' => ['y' => 15, 'x' => 32],
            ],
            // BUTTON Specifics

            self::BACKGROUND => '#FFFFFF',
            self::IMAGE_ID => null,
            self::ALT => '',
            self::LINK_URL => '',
            self::WIDTH => 'auto',
            self::HEIGHT => 35,
            self::ITEM_MOVE => 'center',
            self::HTML => '',
            self::CONTENT_TYPE => 'post',
            self::MODE => 'latest',
            self::CONTENT_ID => null,
            self::CATEGORY_ID => null,
            self::TAG_ID => null,
            self::LIMIT => 1,
            self::LAYOUT => 'featured',
            self::SHOW_IMAGE => true,
            self::SHOW_EXCERPT => true,
            self::SHOW_DATE => false,
            self::BUTTON_TEXT => 'Read More',
            self::HEADING => 'You May Also Like',
            self::SOURCE => 'config',
            self::SOURCE_CONTENT_ID => null,
            self::COLUMNS => [],
            self::BACKGROUND_IMAGE_ID => null,
            self::BLOCKS => [],
            self::EMAIL_SECTION_ID => null,
            self::STYLE => 'image',
            self::VARIANT => 'default',
            self::CUSTOM_LINKS => [],
            default => null
        };
    }

    /**
     * Returns an array of valid items for the enum case.
     *
     * @return array An array of valid items for the enum case.
     */
    public function validItems(): array
    {
        // Align
        return match ($this) {
            self::ALIGN => [
                // key => css style
                'left' => 'text-align: left;',
                'center' => 'text-align: center;',
                'right' => 'text-align: right;',
            ],
            self::LEVEL => [
                // key => css class style
                'h1' => ['class' => 'text-4xl font-bold', 'style' => 'font-size: 2.5rem;line-height: 1.2;'],
                'h2' => ['class' => 'text-3xl font-semibold', 'style' => 'font-size: 2rem;line-height: 1.25;'],
                'h3' => ['class' => 'text-2xl font-medium', 'style' => 'font-size: 1.5rem;line-height: 1.3;'],
                'h4' => ['class' => 'text-xl font-semibold', 'style' => 'font-size: 1.25rem;line-height: 1.4;'],
            ],
            // key => [css class, label, icon] — charCases() reads label/icon straight
            // off this array, so a new case is one entry here, not two.
            self::CHAR_CASE => [
                'normal' => [
                    'class' => 'normal-case',
                    'style' => '',
                    'label' => 'Normal',
                    'icon' => 'no-symbol',
                ],
                'uppercase' => [
                    'class' => 'uppercase',
                    'style' => 'text-transform: uppercase;',
                    'label' => 'Uppercase',
                    'icon' => 'case-upper',
                ],
                'lowercase' => [
                    'class' => 'lowercase',
                    'style' => 'text-transform: lowercase;',
                    'label' => 'Lowercase',
                    'icon' => 'case-lower',
                ],
                'capitalize' => [
                    'class' => 'capitalize',
                    'style' => 'text-transform: capitalize;',
                    'label' => 'Capitalize',
                    'icon' => 'case-sensitive',
                ],
            ],
            self::FONT_SIZE => [
                'xs' => 'font-size: 0.75rem;line-height: 1rem;',
                'sm' => 'font-size: 0.875rem;line-height: 1.25rem;',
                'base' => 'font-size: 1rem;line-height: 1.5rem;',
                'lg' => 'font-size: 1.125rem;line-height: 1.75rem;',
                'xl' => 'font-size: 1.25rem;line-height: 1.75rem;',
                '2xl' => 'font-size: 1.5rem;line-height: 2rem;',
                '3xl' => 'font-size: 1.875rem;line-height: 2.25rem;',
                '4xl' => 'font-size: 2.25rem;line-height: 2.5rem;',
            ],
            self::FONT => self::fonts(),
            self::FONT_WEIGHT => [
                'normal' => ['style' => 'font-weight: 400;', 'label' => 'Normal', 'icon_style' => 'stroke-width: 1px;'],
                'bold' => ['style' => 'font-weight: 500;', 'label' => 'Bold', 'icon_style' => 'stroke-width: 2px;'],
                'bolder' => ['style' => 'font-weight: 700;', 'label' => 'Bolder', 'icon_style' => 'stroke-width: 3px;'],
                'thicker' => ['style' => 'font-weight: 800;', 'label' => 'Bolder', 'icon_style' => 'stroke-width: 4px;'],
            ],
            self::WIDTH => [
                'auto' => 'width: auto;',
                '1/2' => 'width: 50%;',
                '1/3' => 'width: 33.3333%;',
                '2/3' => 'width: 66.6667%;',
                '1/4' => 'width: 25%;',
                '3/4' => 'width: 75%;',
                'full' => 'display: block; width: 100%;',
            ],
            self::ITEM_MOVE => [
                'start' => ['style' => 'margin-left: 0; margin-right: auto;', 'label' => 'Start', 'icon' => 'arrow-left'],
                'center' => ['style' => 'margin: 0 auto;', 'label' => 'Center', 'icon' => 'arrows-pointing-in'],
                'end' => ['style' => 'margin-left: auto; margin-right: 0;', 'label' => 'End', 'icon' => 'arrow-right'],
            ],
            self::RADIUS => self::validRadius(),

            self::CONTENT_TYPE => ['post'],// 'product'],
            self::MODE => ['latest', 'specific'],
            self::LAYOUT => ['featured', 'grid', 'list'],
            self::SOURCE => ['config', 'custom'],
            self::STYLE => ['image', 'text'],
            default => [],
        };
    }

    /**
     * Returns an array of enum values and their corresponding data.
     *
     * @param  self|null|array  $haystack  An instance of the enum, an array of enum values, or null.
     *                                     If null, returns all enum values with their corresponding data.
     *                                     array: An associative array where keys are enum values and values are the corresponding data.
     * @param  mixed  $value  The value associated with the enum case (optional).
     * @return array An associative array of enum values and their corresponding data.
     *
     * @throws \InvalidArgumentException If an invalid enum value is provided.
     */
    public static function make(self|null|array $haystack = null, mixed $value = null): array
    {
        $result = [];

        //
        if (\is_array($haystack)) {
            foreach ($haystack as $key => $item) {
                $self = null;
                $val = '';
                // Get value
                if (\is_int($key) && $item instanceof self) {
                    $self = $item;
                    $val = $item->default();
                } else {
                    $self = self::tryFrom($key);
                    $val = $item;
                }

                // Check if $self is a valid enum case
                if (! ($self instanceof self)) {
                    throw new \InvalidArgumentException("Invalid enum value: {$self}");
                }

                // Spacing is an array, not a string, so we need to handle it differently
                if ($self->isSpacing() || $self->isBorderSpacing() && ! \is_array($val)) {
                    $val = $self->resolveSpacing($val, border: $self->isBorderSpacing());
                }

                // Border is an array, not a string, so we need to handle it differently
                if ($self->isBorder() && ! \is_array($val)) {
                    $val = $self->resolveBorder($val);
                }

                $result[$self->value] = $val;
            }
        } elseif ($haystack instanceof self) {
            $result[$haystack->value] = $haystack->default($value);
        }

        return $result;
    }

    /**
     * Checks if the given enum case requires no validation.
     *
     * @param  self  $current  The enum case to check.
     * @return bool True if the enum case requires no validation, false otherwise.
     */
    private static function noValidity(self $current): bool
    {
        return \in_array(
            $current,
            [
                self::COLOR, self::BACKGROUND, self::HEIGHT, self::BORDER,
            ],
            true
        );
    }

    private static function mustNotBeNull(self $current): bool
    {
        return \in_array(
            $current,
            [
                self::BACKGROUND, self::RADIUS, self::HEIGHT,
                self::BORDER,
            ],
            true
        );
    }

    /**
     * Returns the CSS class for the enum case based on the provided valid item.
     *
     * @param  array  $data  The data array containing the valid item.
     * @param  string  $key  The key to use when retrieving the CSS class from the valid items array (default: 'class').
     * @param  mixed  $default  The default value to return if the valid item is not found (default: '').
     * @param  EmailBlockTypeEnum|null  $type  The email block type enum case (optional).
     * @return string|null The corresponding CSS class for the enum case and valid item.
     */
    public function cssDesign(
        array $data,
        string $key = 'style',
        mixed $default = null,
        ?EmailBlockTypeEnum $type = null
    ): ?string {
        // Data array
        $item = data_get($data, $this->value);

        // Item is
        if ($item === null) {
            if (self::mustNotBeNull($this) && $default === null) {
                return '';
            }
            $item = $this->default($default);
        }

        // Button
        if ($this->isButton()) {
            return $this->resolveButton($item, true);
        }

        // Spacing || Border Spacing
        if ($this->isSpacing() || $this->isBorderSpacing()) {
            return $this->resolveSpacing($item, true, $this->isBorderSpacing());
        }

        // Border
        if ($this->isBorder() && $item !== null) {
            return $this->resolveBorder($item, true);
        }
        // No Validation needed
        if (self::noValidity($this)) {
            return match ($this) {
                self::COLOR => $type?->isDivider() ? "background-color: {$item};" : "color: {$item};",

                // Must not be null
                self::BACKGROUND => "background-color: {$item};",
                self::HEIGHT => "height: {$item}px;",
                default => '',
            };
        }

        // Get valid items and default value
        $validItems = $this->validItems();

        // Check if the provided valid item exists in the valid items array
        return match ($this) {
            self::ALIGN => data_get($validItems, $item),
            self::LEVEL => data_get($validItems, "{$item}.{$key}"),
            self::CHAR_CASE => data_get($validItems, "{$item}.{$key}"),
            self::FONT => data_get($validItems, "{$item}.{$key}"),
            self::FONT_SIZE => data_get($validItems, $item),
            self::FONT_WEIGHT => data_get($validItems, "{$item}.{$key}"),
            self::WIDTH => data_get($validItems, $item),
            self::RADIUS => data_get($validItems, "{$item}.{$key}"),
            self::ITEM_MOVE => 'display: block; '.data_get($validItems, "{$item}.{$key}"),
            default => '',
        };
    }

    /**
     * The char-case picker's label/icon, straight off validItems()'s CHAR_CASE
     * entries — adding or removing a case only ever means editing that one array.
     */
    public static function charCases(): array
    {
        return array_map(
            fn (array $item) => ['label' => $item['label'], 'icon' => $item['icon']],
            self::CHAR_CASE->validItems(),
        );
    }

    /**
     * Returns an array of available fonts with their corresponding labels, CSS classes, and style.
     *
     * @return array An associative array of available fonts with their labels, CSS classes, and style.
     */
    public static function fonts(): array
    {
        return [
            'arial' => [
                'label' => 'Arial',
                'class' => 'font-sans',
                'style' => 'font-family: Arial, Helvetica, sans-serif;',
            ],
            'times' => [
                'label' => 'Times New Roman',
                'class' => 'font-serif',
                'style' => 'font-family: "Times New Roman", Times, serif;',
            ],
            'courier' => [
                'label' => 'Courier New',
                'class' => 'font-mono',
                'style' => 'font-family: "Courier New", Courier, monospace;',
            ],
            'tahoma' => [
                'label' => 'Tahoma',
                'class' => 'font-sans',
                'style' => 'font-family: Tahoma, Geneva, sans-serif;',
            ],
            'verdana' => [
                'label' => 'Verdana',
                'class' => 'font-sans',
                'style' => 'font-family: Verdana, Geneva, sans-serif;',
            ],
            'trebuchet' => [
                'label' => 'Trebuchet MS',
                'class' => 'font-sans',
                'style' => 'font-family: "Trebuchet MS", Helvetica, sans-serif;',
            ],
            'georgia' => [
                'label' => 'Georgia',
                'class' => 'font-serif',
                'style' => 'font-family: Georgia, serif;',
            ],
            'garamond' => [
                'label' => 'Garamond',
                'class' => 'font-serif',
                'style' => 'font-family: Garamond, serif;',
            ],
            'palatino' => [
                'label' => 'Palatino Linotype',
                'class' => 'font-serif',
                'style' => 'font-family: "Palatino Linotype", "Book Antiqua", Palatino, serif;',
            ],
            'impact' => [
                'label' => 'Impact',
                'class' => 'font-sans',
                'style' => 'font-family: Impact, Charcoal, sans-serif;',
            ],
            'comic' => [
                'label' => 'Comic Sans MS',
                'class' => 'font-sans',
                'style' => 'font-family: "Comic Sans MS", cursive, sans-serif;',
            ],
            'bradley' => [
                'label' => 'Bradley Hand',
                'class' => 'font-sans',
                'style' => 'font-family: "Bradley Hand", cursive;',
            ],
            'brush script' => [
                'label' => 'Brush Script MT',
                'class' => 'font-sans',
                'style' => 'font-family: "Brush Script MT", cursive;',
            ],
            'lucida' => [
                'label' => 'Lucida Sans',
                'class' => 'font-sans',
                'style' => 'font-family: "Lucida Sans", "Lucida Grande", sans-serif;',
            ],
            'candara' => [
                'label' => 'Candara',
                'class' => 'font-sans',
                'style' => 'font-family: Candara, Calibri, Segoe, "Segoe UI", Optima, Arial, sans-serif;',
            ],
            'optima' => [
                'label' => 'Optima',
                'class' => 'font-sans',
                'style' => 'font-family: Optima, Segoe, "Segoe UI", Candara, Calibri, Arial, sans-serif;',
            ],
            'futura' => [
                'label' => 'Futura',
                'class' => 'font-sans',
                'style' => 'font-family: Futura, "Trebuchet MS", Arial, sans-serif;',
            ],
        ];
    }

    /**
     * Returns an array of valid border radius values with their corresponding CSS styles and icons.
     *
     * @return array An associative array of valid border radius values with their CSS styles and icons.
     */
    public static function validRadius(): array
    {
        return [
            'none' => ['label' => 'None', 'style' => 'border-radius: 0;', 'icon' => 'square-off'],
            'rounded' => ['label' => 'Rounded', 'style' => 'border-radius: 0.7rem;', 'icon' => 'square'],
            'rounded-md' => ['label' => 'Rounded medium', 'style' => 'border-radius: 2rem;', 'icon' => 'squircle'],
            'rounded-full' => ['label' => 'Rounded full', 'style' => 'border-radius: 999px;', 'icon' => 'circle'],
        ];
    }

    /**
     * Resolves the spacing value into a CSS padding or margin string or an array of padding or margin values.
     *
     * @param  int|array|null  $spacing  The spacing value(s) to resolve. Can be a single integer or
     *                                   an associative array with keys 'top', 'right', 'bottom', and 'left'.
     * @param  bool  $border  Whether to resolve the spacing as border spacing (true) or padding spacing (false).
     * @param  bool  $style  Whether to return the result as a CSS padding string (true) or an array of padding values (false).
     * @return string|array The resolved CSS padding string or an array of padding values.
     *
     * @throws \LogicException If called on a non-SPACING enum case.
     */
    public function resolveSpacing(int|array|null $spacing = 0, bool $style = false, bool $border = false): string|array
    {
        if (! $this->isSpacing() && ! $this->isBorderSpacing()) {
            throw new \LogicException('resolveSpacing() can only be called on the SPACING or BORDER_SPACING or BUTTON enum case.');
        }

        $cssProperty = $border ? 'margin' : 'padding';

        $spacing ??= 0;

        $output = $this->default();

        // Spacing Array
        if (\is_array($spacing)) {
            foreach ($output as $key => $value) {
                if (isset($spacing[$key])) {
                    $output[$key] = (int) $spacing[$key];
                }
            }
        }
        // Single Spacing
        else {
            $output = ['top' => $spacing, 'right' => $spacing, 'bottom' => $spacing, 'left' => $spacing];
        }

        // Return CSS Padding
        if ($style) {

            return sprintf(
                '%s:%spx %spx %spx %spx;',
                $cssProperty,
                data_get($output, 'top', 0),
                data_get($output, 'right', 0),
                data_get($output, 'bottom', 0),
                data_get($output, 'left', 0),
            );
        }

        return $output;
    }

    /**
     * Resolves the border value into a CSS border string or an array of border values.
     *
     * @param  int|array|null  $borderWidth  The border or border width value(s) to resolve
     *                                       an associative array with keys 'width', 'style', and 'color'.
     * @param  bool  $style  Whether to return the result as a CSS border string (true) or an array of border values (false).
     * @return string|array The resolved CSS border string or an array of border values.
     *
     * @throws \LogicException If called on a non-BORDER enum case.
     */
    public function resolveBorder(int|array|null $borderWidth = 0, bool $style = false): string|array
    {
        if (! $this->isBorder()) {
            throw new \LogicException('resolveBorder() can only be called on the BORDER enum case.');
        }

        $borderWidth ??= 0;

        $border = $this->default();

        // Border Array
        if (\is_array($borderWidth)) {
            foreach ($border as $key => $value) {
                if (isset($borderWidth[$key])) {
                    $border[$key] = $borderWidth[$key];
                }
            }
        }
        // Single Border
        else {
            $border = ['width' => $borderWidth, 'style' => 'solid', 'color' => '#E2E8F0'];
        }

        // Return CSS Border
        if ($style) {
            return sprintf(
                'border:%1$spx %2$s %3$s;',
                data_get($border, 'width', 0),
                data_get($border, 'style', 'solid'),
                data_get($border, 'color', '#E2E8F0'),
            );
        }

        return $border;
    }

    public function resolveButton(?array $button = null, bool $style = false): string|array
    {
        if (! $this->isButton()) {
            throw new \LogicException('resolveButton() can only be called on the BUTTON enum case.');
        }

        // Get default button values
        $default = $this->default();
        if ($button === null) {
            $button = $default;
        }

        // Merge button values with default values
        $button = [...$default, ...$button];

        // Return CSS Button
        if ($style) {
            // Spacing
            $spacing = sprintf(
                'padding:%spx %spx;',
                data_get($button, 'spacing.y', 15),
                data_get($button, 'spacing.x', 32),
            );

            // Border
            $borderWidth = 'border-style:solid;border-width:';
            foreach (data_get($button, 'border_width', []) as $side => $width) {
                $borderWidth .= "{$width}px ";
            }
            $borderWidth = trim($borderWidth).';'.
                'border-color:'.data_get($button, 'border_color', '#DC143C').';';

            // Radius
            $radius = self::validRadius()[data_get($button, 'border_radius', 'none')]['style'];

            // Width
            $width = data_get($button, 'full_width', false) ? 'display:block;width:100%;' : 'display:inline-block;';

            // Return CSS Button
            return sprintf(
                'text-decoration: none; text-align: center; background-color:%1$s; %2$s %3$s %4$s %5$s',
                data_get($button, 'background', '#A3E635'),
                $spacing,
                $borderWidth,
                $radius,
                $width
            );
        }

        return $button;
    }

    /**
     * Checks if a field exists in the data array.
     *
     * @param  string  $key  The field to check
     * @param  array  $data  The data array to check in
     * @return bool True if the field exists, false otherwise.
     */
    public static function checkField(string $key, array $data = []): bool
    {
        // Check if key exists in enum
        if (! self::tryFrom($key)) {
            return false;
        }

        // Check if key exists in data
        if ($data && ! \array_key_exists($key, $data)) {
            return false;
        }

        return true;
    }
}
