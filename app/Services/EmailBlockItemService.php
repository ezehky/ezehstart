<?php

namespace App\Services;

use App\Enums\EmailBlockItemEnum;
use App\Enums\EmailBlockTypeEnum;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Arr;

#[Singleton]
class EmailBlockItemService
{
    // ==============================================================================================
    // PUBLIC METHODS
    // ==============================================================================================

    /**
     * Returns the default value for the enum case.
     *
     * @param  string|EmailBlockItemEnum  $item  The enum case to get the default value for.
     * @param  mixed  $alternative  An alternative value to return instead of the default.
     * @return mixed The default value for the enum case or the alternative value if provided.
     */
    public function default(string|EmailBlockItemEnum $item, mixed $alternative = null): mixed
    {
        $item = $this->resolveItem($item);

        return $alternative !== null ? $alternative : match ($item) {
            // No Styling
            EmailBlockItemEnum::TEXT => 'New text',
            EmailBlockItemEnum::LEVEL => 'h1',

            // FONTS
            EmailBlockItemEnum::ALIGN => 'center',
            EmailBlockItemEnum::COLOR => '#0F172A',
            EmailBlockItemEnum::CHAR_CASE => 'normal',
            EmailBlockItemEnum::FONT => 'arial',
            EmailBlockItemEnum::FONT_SIZE => 'sm',
            EmailBlockItemEnum::FONT_WEIGHT => 'normal',

            // BUTTON Specifics
            EmailBlockItemEnum::URL => '',
            // BUTTON Specifics
            // ITEMS
            EmailBlockItemEnum::ITEM_BACKGROUND => '#FFFFFF',
            EmailBlockItemEnum::ITEM_SPACING => ['y' => 15, 'x' => 32],
            EmailBlockItemEnum::ITEM_MOVE => 'center',
            EmailBlockItemEnum::ITEM_RADIUS => 'rounded-sm',

            EmailBlockItemEnum::BORDER_WIDTH => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
            EmailBlockItemEnum::BORDER_COLOR => '#0F172A',

            EmailBlockItemEnum::ELEMENT_DISPLAY => 'inline-block',

            // Image
            EmailBlockItemEnum::IMAGE_ID => null,
            EmailBlockItemEnum::ALT => '',
            EmailBlockItemEnum::LINK_URL => '',

            // Parent Specifics
            EmailBlockItemEnum::PARENT_ALIGN => 'center',

            // Layouts
            EmailBlockItemEnum::BACKGROUND => '#FFFFFF',
            EmailBlockItemEnum::BORDER => ['width' => 0, 'style' => 'solid', 'color' => '#A3E635'],
            EmailBlockItemEnum::RADIUS => 'none',
            EmailBlockItemEnum::SPACING => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            EmailBlockItemEnum::BORDER_SPACING => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],

            EmailBlockItemEnum::WIDTH => 'auto',
            EmailBlockItemEnum::WIDTH_VALUE => 35,
            EmailBlockItemEnum::HEIGHT => 35,

            EmailBlockItemEnum::HTML => '',
            EmailBlockItemEnum::CONTENT_TYPE => 'post',
            EmailBlockItemEnum::MODE => 'latest',
            EmailBlockItemEnum::CONTENT_ID => null,
            EmailBlockItemEnum::CATEGORY_ID => null,
            EmailBlockItemEnum::TAG_ID => null,
            EmailBlockItemEnum::LIMIT => 1,
            EmailBlockItemEnum::LAYOUT => 'featured',
            EmailBlockItemEnum::SHOW_IMAGE => true,
            EmailBlockItemEnum::SHOW_EXCERPT => true,
            EmailBlockItemEnum::SHOW_DATE => false,
            EmailBlockItemEnum::BUTTON_TEXT => 'Read More',
            EmailBlockItemEnum::HEADING => 'You May Also Like',

            // Socials
            EmailBlockItemEnum::SOURCE => 'config',
            EmailBlockItemEnum::SOURCE_CONTENT_ID => null,

            EmailBlockItemEnum::COLUMNS => [],
            EmailBlockItemEnum::BACKGROUND_IMAGE_ID => null,
            EmailBlockItemEnum::BLOCKS => [],
            EmailBlockItemEnum::EMAIL_SECTION_ID => null,
            EmailBlockItemEnum::STYLE => 'image',
            EmailBlockItemEnum::VARIANT => 'default',
            EmailBlockItemEnum::CUSTOM_LINKS => [],
            default => null
        };
    }

    /**
     * Returns an array of valid items for the enum case.
     *
     * @param  string|EmailBlockItemEnum  $item  The enum case to get the valid items for.
     * @return array An array of valid items for the enum case.
     */
    public function validItems(string|EmailBlockItemEnum $item): array
    {
        // Align
        return match ($item) {
            EmailBlockItemEnum::ALIGN,
            EmailBlockItemEnum::PARENT_ALIGN => [
                // key => css style
                'left' => 'text-align: left;',
                'center' => 'text-align: center;',
                'right' => 'text-align: right;',
            ],
            EmailBlockItemEnum::LEVEL => [
                // key => css class style
                'h1' => ['class' => 'text-4xl font-bold', 'style' => 'font-size: 2.5rem;line-height: 1.2;'],
                'h2' => ['class' => 'text-3xl font-semibold', 'style' => 'font-size: 2rem;line-height: 1.25;'],
                'h3' => ['class' => 'text-2xl font-medium', 'style' => 'font-size: 1.5rem;line-height: 1.3;'],
                'h4' => ['class' => 'text-xl font-semibold', 'style' => 'font-size: 1.25rem;line-height: 1.4;'],
            ],
            // key => [css class, label, icon] — charCases() reads label/icon straight
            // off this array, so a new case is one entry here, not two.
            EmailBlockItemEnum::CHAR_CASE => [
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
            EmailBlockItemEnum::FONT_SIZE => [
                'xs' => 'font-size: 0.75rem;line-height: 1rem;',
                'sm' => 'font-size: 0.875rem;line-height: 1.25rem;',
                'base' => 'font-size: 1rem;line-height: 1.5rem;',
                'lg' => 'font-size: 1.125rem;line-height: 1.75rem;',
                'xl' => 'font-size: 1.25rem;line-height: 1.75rem;',
                '2xl' => 'font-size: 1.5rem;line-height: 2rem;',
                '3xl' => 'font-size: 1.875rem;line-height: 2.25rem;',
                '4xl' => 'font-size: 2.25rem;line-height: 2.5rem;',
            ],
            EmailBlockItemEnum::FONT => $this->fonts(),
            EmailBlockItemEnum::FONT_WEIGHT => [
                'normal' => ['style' => 'font-weight: 400;', 'label' => 'Normal', 'icon_style' => 'stroke-width: 1px;'],
                'bold' => ['style' => 'font-weight: 500;', 'label' => 'Bold', 'icon_style' => 'stroke-width: 2px;'],
                'bolder' => ['style' => 'font-weight: 700;', 'label' => 'Bolder', 'icon_style' => 'stroke-width: 3px;'],
                'thicker' => ['style' => 'font-weight: 800;', 'label' => 'Bolder', 'icon_style' => 'stroke-width: 4px;'],
            ],
            EmailBlockItemEnum::WIDTH => [
                'auto' => 'width: auto;',
                'xs' => 'width: 8.53%;',
                'sm' => 'width: 14.28%;',
                'md' => 'width: 20.83%;',
                'lg' => 'width: 25%;',
                '1/2' => 'width: 50%;',
                '1/3' => 'width: 33.3333%;',
                '2/3' => 'width: 66.6667%;',
                '1/4' => 'width: 25%;',
                '3/4' => 'width: 75%;',
                'full' => 'width: 100%;',
            ],

            EmailBlockItemEnum::ELEMENT_DISPLAY => [
                'block' => 'display: block;',
                'inline' => 'display: inline;',
                'inline-block' => 'display: inline-block;',
            ],

            // ITEM Specifics
            EmailBlockItemEnum::ITEM_MOVE => [
                'start' => ['style' => 'margin-left: 0; margin-right: auto;', 'label' => 'Start', 'icon' => 'arrow-left'],
                'center' => ['style' => 'margin: 0 auto;', 'label' => 'Center', 'icon' => 'arrows-pointing-in'],
                'end' => ['style' => 'margin-left: auto; margin-right: 0;', 'label' => 'End', 'icon' => 'arrow-right'],
            ],
            EmailBlockItemEnum::ITEM_RADIUS,
            EmailBlockItemEnum::RADIUS => $this->validRadius(),

            EmailBlockItemEnum::CONTENT_TYPE => ['post'],// 'product'],
            EmailBlockItemEnum::MODE => ['latest', 'specific'],
            EmailBlockItemEnum::LAYOUT => ['featured', 'grid', 'list'],
            EmailBlockItemEnum::SOURCE => ['config', 'custom'],
            EmailBlockItemEnum::STYLE => ['image', 'text'],
            default => [],
        };
    }

    /**
     * Returns an array of enum values and their corresponding data.
     *
     * @param  string|EmailBlockItemEnum|null|array  $haystack  An instance of the enum, an array of enum values, or null.
     *                                                          If null, returns all enum values with their corresponding data.
     *                                                          array: An associative array where keys are enum values and values
     *                                                          are the corresponding data.
     * @param  mixed  $value  The value associated with the enum case (optional).
     * @return array An associative array of enum values and their corresponding data.
     *
     * @throws \InvalidArgumentException If an invalid enum value is provided.
     */
    public function make(string|EmailBlockItemEnum|null|array $haystack = null, mixed $value = null, bool $allLayout = false): array
    {
        $result = [];

        if ($allLayout && \is_array($haystack)) {
            $haystack = [
                ...$haystack,
                EmailBlockItemEnum::BORDER,
                EmailBlockItemEnum::RADIUS,
                EmailBlockItemEnum::BACKGROUND,
                EmailBlockItemEnum::SPACING,
                EmailBlockItemEnum::BORDER_SPACING,
            ];
        }

        //
        if (\is_array($haystack)) {
            foreach ($haystack as $key => $item) {
                $self = null;
                $val = '';
                // Get value
                if (\is_int($key) && $item instanceof EmailBlockItemEnum) {
                    $self = $item;
                    $val = $this->default($item);
                } else {
                    $self = $this->resolveItem($key);
                    $val = $item;
                }

                // Spacing is an array, not a string, so we need to handle it differently
                if ($self->isSpacing() || $self->isBorderSpacing() && ! \is_array($val)) {
                    $val = $this->resolveSpacing($self, $val, border: $self->isBorderSpacing());
                }

                // Border is an array, not a string, so we need to handle it differently
                if ($self->isBorder() && ! \is_array($val)) {
                    $val = $this->resolveBorder($self, $val);
                }

                $result[$self->value] = $val;
            }
        } elseif ($haystack = $this->resolveItem($haystack, true)) {
            $result[$haystack->value] = $this->default($haystack, $value);
        }

        return $result;
    }

    /**
     * The char-case picker's label/icon, straight off validItems()'s CHAR_CASE
     * entries — adding or removing a case only ever means editing that one array.
     */
    public function charCases(): array
    {
        return array_map(
            fn (array $item) => ['label' => $item['label'], 'icon' => $item['icon']],
            $this->validItems(EmailBlockItemEnum::CHAR_CASE),
        );
    }

    /**
     * Returns an array of available fonts with their corresponding labels, CSS classes, and style.
     *
     * @return array An associative array of available fonts with their labels, CSS classes, and style.
     */
    public function fonts(): array
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
    public function validRadius(): array
    {
        return [
            'none' => ['label' => 'None', 'style' => 'border-radius: 0;', 'icon' => 'square-off'],
            'rounded-sm' => ['label' => 'Rounded small', 'style' => 'border-radius: 0.25rem;', 'icon' => 'stop'],
            'rounded' => ['label' => 'Rounded', 'style' => 'border-radius: 0.7rem;', 'icon' => 'square'],
            'rounded-md' => ['label' => 'Rounded medium', 'style' => 'border-radius: 2rem;', 'icon' => 'squircle'],
            'rounded-full' => ['label' => 'Rounded full', 'style' => 'border-radius: 999px;', 'icon' => 'circle'],
        ];
    }

    /**
     * Checks if a field exists in the data array.
     *
     * @param  string|EmailBlockItemEnum  $key  The field to check
     * @param  array  $data  The data array to check in
     * @return bool True if the field exists, false otherwise.
     */
    public function checkField(string|EmailBlockItemEnum $key, array $data = []): bool
    {
        // Check if key exists in enum
        if (! $this->resolveItem($key, true)) {
            return false;
        }

        $item = $this->resolveItem($key);

        // Check if key exists in data
        if ($data && ! \array_key_exists($item->value, $data)) {
            return false;
        }

        return true;
    }

    public function allowedItemFields(array $data)
    {
        return kArrayIntersectKey($data, [
            EmailBlockItemEnum::FONT->value, EmailBlockItemEnum::FONT_WEIGHT->value,
            EmailBlockItemEnum::FONT_SIZE->value, EmailBlockItemEnum::ALIGN->value, EmailBlockItemEnum::CHAR_CASE->value,
            EmailBlockItemEnum::COLOR->value, EmailBlockItemEnum::ITEM_MOVE->value, EmailBlockItemEnum::LINK_URL->value,
            EmailBlockItemEnum::ITEM_BACKGROUND->value, EmailBlockItemEnum::ITEM_SPACING->value,
            EmailBlockItemEnum::ITEM_RADIUS->value, EmailBlockItemEnum::WIDTH->value, EmailBlockItemEnum::WIDTH_VALUE->value,
            EmailBlockItemEnum::HEIGHT->value,
            EmailBlockItemEnum::BORDER_WIDTH->value, EmailBlockItemEnum::BORDER_COLOR->value,
            EmailBlockItemEnum::PARENT_ALIGN->value,

        ]);
    }

    /**
     * Returns the CSS classes and styles for the given email block item and data.
     *
     * @param  EmailBlockTypeEnum  $type  The email block item to get the CSS for.
     * @param  array  $data  The data array containing the valid item.
     * @return array An associative array containing 'classes', 'style', and 'container' keys with their corresponding values.
     */
    public function getCss(EmailBlockTypeEnum $type, array $data): array
    {
        $classes = $style = $parent = $container = [];

        // Social
        if ($type->isSocials()) {
            // Image: remove all
            $data = (data_get($data, 'style', 'image') === 'image') ?
                Arr::except($data, [
                    EmailBlockItemEnum::COLOR->value,
                    EmailBlockItemEnum::CHAR_CASE->value,
                    EmailBlockItemEnum::FONT->value,
                    EmailBlockItemEnum::FONT_SIZE->value,
                    EmailBlockItemEnum::FONT_WEIGHT->value,
                    EmailBlockItemEnum::ITEM_BACKGROUND->value,
                    EmailBlockItemEnum::ITEM_RADIUS->value,
                    EmailBlockItemEnum::ITEM_SPACING->value,
                ]) :
                // Text
                Arr::except($data, [
                    EmailBlockItemEnum::WIDTH_VALUE->value,
                    EmailBlockItemEnum::HEIGHT->value,
                ]);
        }

        // Resolve the type to an EmailBlockTypeEnum
        foreach ($data as $key => $value) {
            $item = $this->resolveItem($key);

            if ($item->isLayoutItem()) {
                $container[] = $this->cssDesign($key, $data);
            } elseif ($item->isParentItem()) {
                $parent[] = $this->cssDesign($key, $data);
            } else {
                $style[] = $this->cssDesign($key, $data, type: $type);
            }
        }

        // Heading
        if ($type->isHeading()) {
            $classes[] = $this->cssDesign(EmailBlockItemEnum::LEVEL, $data, 'class');
        }

        // Button
        if ($type->isButton()) {
            $style[] = 'text-decoration: none; text-align: center;';
        }

        // Divider
        if ($type->isDivider()) {
            $style[] = 'border-width:0;';
        }

        //
        return [
            'classes' => implode(' ', $classes),
            'style' => implode('', $style),
            'container' => implode('', $container),
            'parent' => implode('', $parent),
        ];
    }

    // ==============================================================================================
    // PRIVATE METHODS
    // ==============================================================================================

    /**
     * Resolves the given item to an instance of EmailBlockItemEnum.
     *
     * @param  string|EmailBlockItemEnum  $item  The item to resolve.
     * @param  bool  $returnBool  Whether to return a boolean indicating if the item is valid (true) or not (false).
     * @return EmailBlockItemEnum|bool The resolved EmailBlockItemEnum instance or a boolean indicating validity.
     *
     * @throws \LogicException If the provided item is not a valid EmailBlockItemEnum value and $returnBool is false.
     */
    private function resolveItem(string|EmailBlockItemEnum $item, bool $returnBool = false): EmailBlockItemEnum|bool
    {
        // Resolve the item to an EmailBlockItemEnum
        if ($item instanceof EmailBlockItemEnum) {
            return $returnBool ? true : $item;
        }

        // Try to resolve the item from the enum
        if ($item = EmailBlockItemEnum::tryFrom($item)) {
            return $returnBool ? true : $item;
        }

        if ($returnBool) {
            return false;
        }

        throw new \LogicException("Invalid EmailBlockItemEnum value: {$item}");
    }

    /**
     * Returns the CSS class for the enum case based on the provided valid item.
     *
     * @param  string|EmailBlockItemEnum  $item  The enum case to get the CSS class for.
     * @param  array  $data  The data array containing the valid item.
     * @param  string  $key  The key to use when retrieving the CSS class from the valid items array (default: 'class').
     * @param  mixed  $default  The default value to return if the valid item is not found (default: '').
     * @param  EmailBlockTypeEnum|null  $type  The email block type enum case (optional).
     * @return string|null The corresponding CSS class for the enum case and valid item.
     */
    private function cssDesign(
        string|EmailBlockItemEnum $item,
        array $data,
        string $key = 'style',
        mixed $default = null,
        ?EmailBlockTypeEnum $type = null
    ): ?string {
        $item = $this->resolveItem($item);

        // Data array
        $selected = data_get($data, $item->value);

        // Item is
        if ($selected === null) {
            $selected = $this->default($item, $default);
        }

        // Spacing || Border Spacing
        if ($item->isSpacing() || $item->isBorderSpacing()) {
            return $this->resolveSpacing($item, $selected, true, $item->isBorderSpacing());
        }

        // Border && Border Width
        if ($item->isBorder() || $item->isBorderWidth() && $item !== null) {
            return $this->resolveBorder($item, $selected, true, $item->isBorderWidth());
        }

        // Item Spacing
        if ($item->isItemSpacing() && Arr::has($data[$item->value], ['x', 'y'])) {
            return \sprintf(
                'padding:%spx %spx;',
                data_get($selected, 'y', 15),
                data_get($selected, 'x', 32),
            );
        }

        // No Validation needed
        if ($this->noValidity($item)) {
            return match ($item) {
                EmailBlockItemEnum::COLOR => $type?->isDivider() ? "background-color: {$selected};" : "color: {$selected};",
                EmailBlockItemEnum::BACKGROUND,
                EmailBlockItemEnum::ITEM_BACKGROUND => "background-color: {$selected};",
                EmailBlockItemEnum::HEIGHT => "height: {$selected}px;",
                EmailBlockItemEnum::WIDTH_VALUE => "width: {$selected}px;",
                EmailBlockItemEnum::BORDER_COLOR => "border-color: {$selected};",
                default => '',
            };
        }

        // Get valid items and default value
        $validItems = $this->validItems($item);

        $extraStyle = '';

        // Check if the provided valid item exists in the valid items array
        return match ($item) {
            // Without Key
            EmailBlockItemEnum::ALIGN,
            EmailBlockItemEnum::FONT_SIZE,
            EmailBlockItemEnum::WIDTH,
            EmailBlockItemEnum::ELEMENT_DISPLAY,
            EmailBlockItemEnum::PARENT_ALIGN => $extraStyle.data_get($validItems, $selected),

            // With Key
            EmailBlockItemEnum::LEVEL,
            EmailBlockItemEnum::CHAR_CASE,
            EmailBlockItemEnum::FONT,
            EmailBlockItemEnum::FONT_WEIGHT,
            EmailBlockItemEnum::RADIUS,
            EmailBlockItemEnum::ITEM_RADIUS,
            EmailBlockItemEnum::ITEM_MOVE => data_get($validItems, "{$selected}.{$key}"),
            default => '',
        };
    }

    /**
     * Checks if the given enum case requires no validation.
     *
     * @param  EmailBlockItemEnum  $current  The enum case to check for validation requirements.
     * @return bool True if the enum case requires no validation, false otherwise.
     */
    private function noValidity(EmailBlockItemEnum $current): bool
    {
        return \in_array(
            $current,
            [
                EmailBlockItemEnum::COLOR, EmailBlockItemEnum::HEIGHT,
                EmailBlockItemEnum::BACKGROUND, EmailBlockItemEnum::ITEM_BACKGROUND,
                EmailBlockItemEnum::BORDER_COLOR, EmailBlockItemEnum::WIDTH_VALUE,
            ],
            true
        );
    }

    /**
     * Resolves the spacing value into a CSS padding or margin string or an array of padding or margin values.
     *
     * @param  EmailBlockItemEnum  $item  The email block item for which to resolve the spacing.
     * @param  int|array|null  $spacing  The spacing value(s) to resolve. Can be a single integer or
     *                                   an associative array with keys 'top', 'right', 'bottom', and 'left'.
     * @param  bool  $border  Whether to resolve the spacing as border spacing (true) or padding spacing (false).
     * @param  bool  $style  Whether to return the result as a CSS padding string (true) or an array of padding values (false).
     * @return string|array The resolved CSS padding string or an array of padding values.
     *
     * @throws \LogicException If called on a non-SPACING enum case.
     */
    private function resolveSpacing(EmailBlockItemEnum $item, int|array|null $spacing = 0, bool $style = false, bool $border = false): string|array
    {
        $item = $this->resolveItem($item);
        if (! $item->isSpacing() && ! $item->isBorderSpacing()) {
            throw new \LogicException('resolveSpacing() can only be called on the SPACING or BORDER_SPACING or BUTTON enum case.');
        }

        $cssProperty = $border ? 'margin' : 'padding';

        $spacing ??= 0;

        $output = $this->default($item);

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
     * @param  EmailBlockItemEnum  $item  The email block item for which to resolve the border.
     * @param  int|array|null  $value  The border or border width value(s) to resolve
     *                                 an associative array with keys 'width', 'style', and 'color'.
     * @param  bool  $style  Whether to return the result as a CSS border string (true) or an array of border values (false).
     * @param  bool  $isBorderWidth  Whether to include the border width in the output (true) or not (false).
     * @return string|array The resolved CSS border string or an array of border values.
     *
     * @throws \LogicException If called on a non-BORDER enum case.
     */
    private function resolveBorder(
        EmailBlockItemEnum $item,
        int|array|null $value = 0,
        bool $style = false,
        bool $isBorderWidth = false
    ): string|array {
        $item = $this->resolveItem($item);

        if (! $item->isBorder() && ! $item->isBorderWidth()) {
            throw new \LogicException('resolveBorder() can only be called on the BORDER enum case.');
        }

        $value ??= 0;

        $border = $this->default($item);

        // Border Array
        if (\is_array($value)) {
            foreach ($border as $key => $item) {
                if (isset($value[$key])) {
                    $border[$key] = $value[$key];
                }
            }
        }
        // Single Border
        elseif (! $isBorderWidth) {
            $border = ['width' => $value, 'style' => 'solid', 'color' => '#E2E8F0'];
        }

        // Border Width Array
        else {
            // Give every key the value inside $value
            foreach ($border as $key => $item) {
                $border[$key] = $value;
            }
        }

        // Return CSS Border
        if ($style) {
            if ($isBorderWidth) {
                // is there another way to present below code
                $borderWidth = 'border-style:solid;border-width:';
                foreach ($border as $side => $width) {
                    $borderWidth .= "{$width}px ";
                }

                return "{$borderWidth};";
            }

            // Layout Border
            return \sprintf(
                'border:%1$spx %2$s %3$s;',
                data_get($border, 'width', 0),
                data_get($border, 'style', 'solid'),
                data_get($border, 'color', '#E2E8F0'),
            );
        }

        return $border;
    }
}
