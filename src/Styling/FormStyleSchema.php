<?php

namespace TMAn1An\FormBuilder\Styling;

/**
 * The form-level "Design" settings (forms.style_settings): groups,
 * properties, defaults, validation and CSS custom properties. Single source
 * of truth for the builder's Design panel, save-time validation and
 * render-time CSS. The "button" group IS ButtonStyleSchema, shared with
 * the page Button block. See docs/FORMS.md §Style system.
 */
final class FormStyleSchema extends GroupedStyleSchema
{
    public static function definition(): array
    {
        $px = fn (string $label, int $min, int $max, int $default, string $var) => compact('label', 'min', 'max', 'default', 'var') + ['type' => 'px'];
        $color = fn (string $label, string $default, string $var) => compact('label', 'default', 'var') + ['type' => 'color'];
        $enum = fn (string $label, array $options, string $default, string $var) => compact('label', 'options', 'default', 'var') + ['type' => 'enum'];

        return [
            'form' => [
                'label' => 'Form container',
                'properties' => [
                    'max_width' => $px('Max width', 320, 1600, 720, '--ff-max-width'),
                    'alignment' => $enum('Alignment', [
                        'left' => ['label' => 'Left', 'css' => '0 auto 0 0'],
                        'center' => ['label' => 'Center', 'css' => '0 auto'],
                        'right' => ['label' => 'Right', 'css' => '0 0 0 auto'],
                    ], 'center', '--ff-margin'),
                    'background' => $color('Background', '#ffffff', '--ff-bg'),
                    'text_color' => $color('Text color', '#1a1a1a', '--ff-text'),
                    'font_family' => $enum('Font', [
                        'inherit' => ['label' => 'Site default', 'css' => 'inherit'],
                        'sans' => ['label' => 'System sans-serif', 'css' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'],
                        'serif' => ['label' => 'Serif', 'css' => 'Georgia, "Times New Roman", serif'],
                        'mono' => ['label' => 'Monospace', 'css' => 'ui-monospace, Consolas, monospace'],
                    ], 'inherit', '--ff-font'),
                    'padding' => $px('Padding', 0, 96, 32, '--ff-padding'),
                    'field_gap' => $px('Space between fields', 0, 64, 20, '--ff-gap'),
                    'border_width' => $px('Border width', 0, 12, 1, '--ff-border-width'),
                    'border_color' => $color('Border color', '#dde3ea', '--ff-border-color'),
                    'border_radius' => $px('Corner radius', 0, 48, 12, '--ff-radius'),
                    'shadow' => $enum('Shadow', [
                        'none' => ['label' => 'None', 'css' => 'none'],
                        'sm' => ['label' => 'Subtle', 'css' => '0 1px 3px rgba(0,0,0,.08)'],
                        'md' => ['label' => 'Medium', 'css' => '0 4px 14px rgba(0,0,0,.10)'],
                        'lg' => ['label' => 'Strong', 'css' => '0 12px 32px rgba(0,0,0,.16)'],
                    ], 'sm', '--ff-shadow'),
                ],
            ],
            'label' => [
                'label' => 'Labels',
                'properties' => [
                    'color' => $color('Color', '#002855', '--ff-label-color'),
                    'font_size' => $px('Font size', 10, 32, 15, '--ff-label-size'),
                    'font_weight' => $enum('Weight', [
                        '400' => ['label' => 'Regular', 'css' => '400'],
                        '500' => ['label' => 'Medium', 'css' => '500'],
                        '600' => ['label' => 'Semi-bold', 'css' => '600'],
                        '700' => ['label' => 'Bold', 'css' => '700'],
                    ], '600', '--ff-label-weight'),
                    'spacing' => $px('Space below label', 0, 24, 6, '--ff-label-gap'),
                ],
            ],
            'input' => [
                'label' => 'Inputs',
                'properties' => [
                    'text_color' => $color('Text color', '#1a1a1a', '--ff-input-text'),
                    'background' => $color('Background', '#ffffff', '--ff-input-bg'),
                    'border_color' => $color('Border color', '#c5ced8', '--ff-input-border'),
                    'focus_border_color' => $color('Focus border color', '#00629b', '--ff-input-focus'),
                    'border_width' => $px('Border width', 0, 6, 1, '--ff-input-border-width'),
                    'radius' => $px('Corner radius', 0, 30, 8, '--ff-input-radius'),
                    'padding_y' => $px('Vertical padding', 2, 24, 10, '--ff-input-py'),
                    'padding_x' => $px('Horizontal padding', 4, 32, 12, '--ff-input-px'),
                    'font_size' => $px('Font size', 12, 24, 15, '--ff-input-size'),
                ],
            ],
            'button' => [
                'label' => 'Submit button',
                'properties' => ButtonStyleSchema::properties(),
            ],
            'messages' => [
                'label' => 'Errors & messages',
                'properties' => [
                    'error_color' => $color('Error text', '#b42318', '--ff-error'),
                    'error_border_color' => $color('Error border', '#d92d20', '--ff-error-border'),
                    'success_background' => $color('Success background', '#ecfdf3', '--ff-success-bg'),
                    'success_text_color' => $color('Success text', '#05603a', '--ff-success-text'),
                    'success_border_color' => $color('Success border', '#a6f4c5', '--ff-success-border'),
                ],
            ],
        ];
    }
}
