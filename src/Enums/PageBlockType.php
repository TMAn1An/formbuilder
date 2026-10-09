<?php

namespace TMAn1An\FormBuilder\Enums;

/**
 * Page Builder block types -- the single source of truth for what each
 * block is and which settings it accepts (validated by
 * Services\Pages\PageBlockSettingsSchema). `section` and `columns` are
 * containers holding one level of non-container children.
 */
enum PageBlockType: string
{
    case Section = 'section';
    case Columns = 'columns';
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Image = 'image';
    case Button = 'button';
    case Divider = 'divider';
    case Spacer = 'spacer';
    case Form = 'form';
    case Html = 'html';

    public function label(): string
    {
        return match ($this) {
            self::Section => 'Section',
            self::Columns => 'Columns',
            self::Heading => 'Heading',
            self::Paragraph => 'Paragraph',
            self::Image => 'Image',
            self::Button => 'Button',
            self::Divider => 'Divider',
            self::Spacer => 'Spacer',
            self::Form => 'Form',
            self::Html => 'Custom HTML',
        };
    }

    public function isContainer(): bool
    {
        return $this === self::Section || $this === self::Columns;
    }

    /** @return list<string> */
    public function settingKeys(): array
    {
        return match ($this) {
            self::Section => ['background_color', 'text_color', 'padding_y', 'padding_x', 'radius'],
            self::Columns => ['count', 'gap', 'vertical_align'],
            self::Heading => ['text', 'level', 'align', 'font_size', 'font_weight', 'color', 'margin_top', 'margin_bottom'],
            self::Paragraph => ['content', 'align', 'font_size', 'color'],
            self::Image => ['media_id', 'alt', 'width', 'max_width', 'align', 'radius', 'link_url', 'new_tab'],
            self::Button => ['text', 'url', 'new_tab', 'style'],
            self::Divider => ['color', 'thickness', 'width', 'margin_y'],
            self::Spacer => ['height'],
            self::Form => ['form_id', 'hide_title', 'hide_description'],
            self::Html => ['content'],
        };
    }

    /** @return array<string, array<string, mixed>> */
    public static function builderMeta(): array
    {
        $meta = [];
        foreach (self::cases() as $case) {
            $meta[$case->value] = ['label' => $case->label(), 'isContainer' => $case->isContainer(), 'settings' => $case->settingKeys()];
        }

        return $meta;
    }
}
