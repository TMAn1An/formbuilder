<?php

namespace TMAn1An\FormBuilder\Styling;

/**
 * The ONE definition of a button's style, shared by the form submit button
 * (FormStyleSchema's "button" group) and the page Button block. Both render
 * through the same `.ff-button` CSS rule driven by these `--ff-btn-*`
 * custom properties, so sizing behaves identically everywhere.
 *
 * Sizing changes the real box, not just the text: width is
 * auto | full | custom (a validated px/%/rem length, capped at 100% of the
 * container so it can't overflow on phones), plus optional min width,
 * min height, and padding.
 */
final class ButtonStyleSchema
{
    use StyleProperties;

    public const LENGTH_MAX = ['px' => 2000, '%' => 100, 'rem' => 120];

    /** @return array<string, array<string, mixed>> */
    public static function properties(): array
    {
        $px = fn (string $label, int $min, int $max, int $default, string $var) => compact('label', 'min', 'max', 'default', 'var') + ['type' => 'px'];
        $color = fn (string $label, string $default, string $var) => compact('label', 'default', 'var') + ['type' => 'color'];
        $length = fn (string $label, string $default, string $var) => compact('label', 'default', 'var') + ['type' => 'length', 'max' => self::LENGTH_MAX];

        return [
            'font_size' => $px('Font size', 10, 40, 16, '--ff-btn-size'),
            'font_weight' => ['type' => 'enum', 'label' => 'Font weight', 'default' => '600', 'var' => '--ff-btn-weight', 'options' => [
                '400' => ['label' => 'Regular', 'css' => '400'],
                '500' => ['label' => 'Medium', 'css' => '500'],
                '600' => ['label' => 'Semi-bold', 'css' => '600'],
                '700' => ['label' => 'Bold', 'css' => '700'],
                '800' => ['label' => 'Extra-bold', 'css' => '800'],
            ]],
            'text_color' => $color('Text color', '#ffffff', '--ff-btn-text'),
            'background' => $color('Background', '#00629b', '--ff-btn-bg'),
            'hover_background' => $color('Hover background', '#002855', '--ff-btn-bg-hover'),
            'border_color' => $color('Border color', '#00629b', '--ff-btn-border-color'),
            'border_width' => $px('Border width', 0, 10, 0, '--ff-btn-border-width'),
            'radius' => $px('Corner radius', 0, 60, 8, '--ff-btn-radius'),
            'width' => ['type' => 'enum', 'label' => 'Width', 'default' => 'auto', 'var' => '--ff-btn-width', 'options' => [
                'auto' => ['label' => 'Fit content', 'css' => 'auto'],
                'full' => ['label' => 'Full width', 'css' => '100%'],
                'custom' => ['label' => 'Custom', 'css' => '@custom_width'],
            ]],
            'custom_width' => $length('Custom width (px, % or rem)', '240px', '--ff-btn-custom-width'),
            'min_width' => $length('Minimum width', '0px', '--ff-btn-min-width'),
            'min_height' => $length('Minimum height', '0px', '--ff-btn-min-height'),
            'padding_y' => $px('Vertical padding', 0, 60, 12, '--ff-btn-py'),
            'padding_x' => $px('Horizontal padding', 0, 120, 28, '--ff-btn-px'),
            'alignment' => ['type' => 'enum', 'label' => 'Alignment', 'default' => 'left', 'var' => '--ff-btn-align', 'options' => [
                'left' => ['label' => 'Left', 'css' => 'flex-start'],
                'center' => ['label' => 'Center', 'css' => 'center'],
                'right' => ['label' => 'Right', 'css' => 'flex-end'],
            ]],
        ];
    }

    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return array_map(fn ($def) => $def['default'], self::properties());
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix): array
    {
        $rules = [$prefix => ['nullable', 'array']];
        foreach (self::properties() as $key => $def) {
            $rules["{$prefix}.{$key}"] = self::rulesFor($def);
        }

        return $rules;
    }

    /** Known keys only, invalid/missing values replaced by defaults. @return array<string, string|int> */
    public static function normalize(?array $values): array
    {
        $normalized = [];
        foreach (self::properties() as $key => $def) {
            $normalized[$key] = self::cleanValue($def, $values[$key] ?? null) ?? $def['default'];
        }

        return $normalized;
    }

    /** `--ff-btn-bg:#00629b;--ff-btn-width:min(240px, 100%);...` */
    public static function cssDeclarations(?array $values): string
    {
        $normalized = self::normalize($values);
        $declarations = [];
        foreach (self::properties() as $key => $def) {
            $declarations[] = $def['var'].':'.self::cssValue($def, $normalized[$key], $normalized);
        }

        return implode(';', $declarations);
    }
}
