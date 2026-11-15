<?php

namespace TMAn1An\FormBuilder\Enums;

/**
 * Every element the form builder can place. The single source of truth for
 * what each type IS (collects a value? has options? which settings apply?)
 * -- the builder UI, the server-side definition validator, the public
 * renderer, submission validation, and the Excel export all ask this enum
 * rather than branching on type names themselves. Adding a later type
 * (file upload, rating, ...) means adding a case here plus its renderer
 * markup and value rules, nothing more. See docs/FORMS.md §Field types.
 */
enum FormFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Email = 'email';
    case Number = 'number';
    case Phone = 'phone';
    case Date = 'date';
    case Time = 'time';
    case DateTime = 'datetime';
    case Select = 'select';
    case Radio = 'radio';
    case CheckboxGroup = 'checkbox_group';
    case Checkbox = 'checkbox';
    case Hidden = 'hidden';
    case Image = 'image';
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Divider = 'divider';
    case Section = 'section';
    case Html = 'html';
    case ImageBlock = 'image_block';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::LongText => 'Long text',
            self::Email => 'Email',
            self::Number => 'Number',
            self::Phone => 'Phone',
            self::Date => 'Date',
            self::Time => 'Time',
            self::DateTime => 'Date & time',
            self::Select => 'Dropdown',
            self::Radio => 'Radio buttons',
            self::CheckboxGroup => 'Checkbox group',
            self::Checkbox => 'Single checkbox',
            self::Hidden => 'Hidden field',
            self::Image => 'Image upload',
            self::Heading => 'Heading',
            self::Paragraph => 'Paragraph',
            self::Divider => 'Divider',
            self::Section => 'Section',
            self::Html => 'HTML block',
            self::ImageBlock => 'Image',
        };
    }

    /** Palette grouping in the builder's left column. */
    public function group(): string
    {
        return match ($this) {
            self::Select, self::Radio, self::CheckboxGroup, self::Checkbox => 'choice',
            self::Heading, self::Paragraph, self::Divider, self::Section, self::Html, self::ImageBlock => 'layout',
            default => 'input',
        };
    }

    /** Produces a stored submission value (includes Hidden, whose value is server-set). */
    public function collectsValue(): bool
    {
        return $this->group() !== 'layout';
    }

    /** The visitor types/chooses the value themselves. */
    public function acceptsUserInput(): bool
    {
        return $this->collectsValue() && $this !== self::Hidden;
    }

    public function supportsRequired(): bool
    {
        return $this->acceptsUserInput();
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::Radio, self::CheckboxGroup], true);
    }

    public function isMultiValue(): bool
    {
        return $this === self::CheckboxGroup;
    }

    /** Can be referenced by another field's conditional-visibility rule. */
    public function canDriveConditions(): bool
    {
        // An uploaded file has no comparable text value.
        return $this->acceptsUserInput() && $this !== self::Image;
    }

    /** Value arrives as an uploaded file (multipart), not as text. */
    public function isFileUpload(): bool
    {
        return $this === self::Image;
    }

    /**
     * Whitelisted `settings` keys for this type -- anything else in a saved
     * payload is dropped, never persisted. Validation rules per key live in
     * TMAn1An\FormBuilder\Services\Forms\FieldSettingsSchema.
     *
     * @return list<string>
     */
    public function settingKeys(): array
    {
        $common = ['width', 'css_class'];
        $input = ['help_text', 'show_in_list', 'disabled'];

        $specific = match ($this) {
            self::Text => ['placeholder', 'default_value', 'max_length', 'pattern', 'pattern_message', 'read_only', 'unique'],
            self::LongText => ['placeholder', 'default_value', 'max_length', 'rows', 'read_only'],
            // `unique`: no two submissions of the form may share this value
            // (e.g. a student ID) -- FormSubmissionService::assertUnique().
            self::Email, self::Phone => ['placeholder', 'default_value', 'read_only', 'unique'],
            self::Number => ['placeholder', 'default_value', 'min', 'max', 'step', 'read_only', 'unique'],
            self::Date, self::Time, self::DateTime => ['default_value', 'min', 'max', 'read_only'],
            self::Select => ['placeholder', 'default_value', 'options'],
            self::Radio => ['default_value', 'options', 'options_layout'],
            self::CheckboxGroup => ['options', 'options_layout'],
            self::Checkbox => ['checkbox_text', 'default_value'],
            self::Hidden => ['default_value'],
            self::Heading => ['content', 'heading_level', 'text_align'],
            self::Paragraph => ['content', 'text_align'],
            self::Section => ['content'],
            self::Html => ['content'],
            self::Divider => [],
            self::Image => ['max_size_kb', 'allowed_types'],
            // A picture shown on the form (not a visitor upload): an image from
            // the shared media library (page_media), like the page builder's.
            self::ImageBlock => ['media_id', 'alt', 'max_width', 'align', 'radius', 'link_url', 'new_tab'],
        };

        $base = match (true) {
            $this === self::Hidden => ['show_in_list'],
            $this === self::Image => array_merge($common, ['help_text', 'show_in_list']),
            $this->acceptsUserInput() => array_merge($common, $input),
            default => $common,
        };

        return array_values(array_unique(array_merge($base, $specific)));
    }

    /** `type` attribute for single-line `<input>` types; null for everything else. */
    public function htmlInputType(): ?string
    {
        return match ($this) {
            self::Text => 'text',
            self::Email => 'email',
            self::Number => 'number',
            self::Phone => 'tel',
            self::Date => 'date',
            self::Time => 'time',
            self::DateTime => 'datetime-local',
            default => null,
        };
    }

    /** Wire format of date-ish values (matches the browser's native input value format). */
    public function dateFormat(): ?string
    {
        return match ($this) {
            self::Date => 'Y-m-d',
            self::Time => 'H:i',
            self::DateTime => 'Y-m-d\TH:i',
            default => null,
        };
    }

    /**
     * Metadata the builder JS needs to render palettes/settings panels --
     * derived from the methods above so the client can never disagree with
     * the server about what a type supports.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builderMeta(): array
    {
        $meta = [];
        foreach (self::cases() as $case) {
            $meta[$case->value] = [
                'label' => $case->label(),
                'group' => $case->group(),
                'collectsValue' => $case->collectsValue(),
                'acceptsUserInput' => $case->acceptsUserInput(),
                'supportsRequired' => $case->supportsRequired(),
                'hasOptions' => $case->hasOptions(),
                'isMultiValue' => $case->isMultiValue(),
                'canDriveConditions' => $case->canDriveConditions(),
                'htmlInputType' => $case->htmlInputType(),
                'isFileUpload' => $case->isFileUpload(),
                'settings' => $case->settingKeys(),
            ];
        }

        return $meta;
    }
}
