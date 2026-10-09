<?php

namespace TMAn1An\FormBuilder\Styling;

/**
 * Page-level design (pages.style_settings): deliberately small -- content
 * width, colors, padding and spacing -- not a theme builder. Emitted as
 * `--pb-*` custom properties consumed by resources/dist/css/pages.css.
 */
final class PageStyleSchema extends GroupedStyleSchema
{
    public static function definition(): array
    {
        return [
            'page' => [
                'label' => 'Page',
                'properties' => [
                    'max_width' => ['type' => 'px', 'label' => 'Max content width', 'min' => 320, 'max' => 1920, 'default' => 1100, 'var' => '--pb-max-width'],
                    'background' => ['type' => 'color', 'label' => 'Page background', 'default' => '#f4f6f8', 'var' => '--pb-bg'],
                    'content_background' => ['type' => 'color', 'label' => 'Content background', 'default' => '#ffffff', 'var' => '--pb-content-bg'],
                    'text_color' => ['type' => 'color', 'label' => 'Base text color', 'default' => '#1a1a1a', 'var' => '--pb-text'],
                    'padding' => ['type' => 'px', 'label' => 'Content padding', 'min' => 0, 'max' => 120, 'default' => 40, 'var' => '--pb-padding'],
                    'section_spacing' => ['type' => 'px', 'label' => 'Space between blocks', 'min' => 0, 'max' => 120, 'default' => 24, 'var' => '--pb-gap'],
                    'radius' => ['type' => 'px', 'label' => 'Content corner radius', 'min' => 0, 'max' => 48, 'default' => 12, 'var' => '--pb-radius'],
                ],
            ],
        ];
    }
}
