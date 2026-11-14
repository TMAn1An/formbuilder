<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use Illuminate\Validation\Rule;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Services\Pages\PageBlockSettingsSchema;

/**
 * Validation + normalization for one field's `settings` JSON. Which keys a
 * type may carry comes from FormFieldType::settingKeys(); this class only
 * knows how to validate and clean each key. Unknown keys never survive
 * normalize(), so the stored JSON can't accumulate arbitrary client data.
 */
final class FieldSettingsSchema
{
    public const WIDTHS = [100, 75, 66, 50, 33, 25];

    public const MAX_OPTIONS = 200;

    public const MAX_HTML_LENGTH = 20000;

    /** Image-upload formats: settings key => detected MIME type. SVG is deliberately absent. */
    public const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Largest max_width (px) an Image block may be given. */
    public const IMAGE_MAX_WIDTH = 2000;

    /** @return array<string, list<mixed>> */
    public static function rules(FormFieldType $type, string $prefix): array
    {
        $rules = [$prefix => ['nullable', 'array']];

        foreach ($type->settingKeys() as $key) {
            foreach (self::rulesForKey($type, $key, "{$prefix}.{$key}") as $path => $pathRules) {
                $rules[$path] = $pathRules;
            }
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    private static function rulesForKey(FormFieldType $type, string $key, string $path): array
    {
        $dateFormat = $type->dateFormat();
        // min/max mean "numeric bounds" for Number and "date bounds" for the date-ish types.
        $boundRules = match (true) {
            $type === FormFieldType::Number => ['nullable', 'numeric'],
            $dateFormat !== null => ['nullable', 'date_format:'.$dateFormat],
            default => ['nullable', 'string', 'max:255'],
        };
        $defaultValueRules = match (true) {
            $type === FormFieldType::Checkbox => ['nullable', 'boolean'],
            $type === FormFieldType::Number, $dateFormat !== null => $boundRules,
            default => ['nullable', 'string', 'max:1000'],
        };

        return match ($key) {
            'width' => [$path => ['nullable', Rule::in(self::WIDTHS)]],
            'css_class' => [$path => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\- ]*$/']],
            'placeholder', 'pattern_message' => [$path => ['nullable', 'string', 'max:255']],
            'help_text' => [$path => ['nullable', 'string', 'max:1000']],
            'checkbox_text' => [$path => ['nullable', 'string', 'max:500']],
            'show_in_list', 'disabled', 'read_only' => [$path => ['nullable', 'boolean']],
            'default_value' => [$path => $defaultValueRules],
            'min', 'max' => [$path => $boundRules],
            'step' => [$path => ['nullable', 'numeric', 'gt:0']],
            'max_length' => [$path => ['nullable', 'integer', 'min:1', 'max:65535']],
            'rows' => [$path => ['nullable', 'integer', 'min:2', 'max:20']],
            'pattern' => [$path => ['nullable', 'string', 'max:255']],
            'options' => [
                $path => ['required', 'array', 'min:1', 'max:'.self::MAX_OPTIONS],
                "{$path}.*" => ['array'],
                "{$path}.*.label" => ['required', 'string', 'max:255'],
                "{$path}.*.value" => ['required', 'string', 'max:255'],
            ],
            'options_layout' => [$path => ['nullable', Rule::in(['stacked', 'inline'])]],
            'max_size_kb' => [$path => ['nullable', 'integer', 'min:16', 'max:'.(int) config('formbuilder.uploads.max_kb', 10240)]],
            'allowed_types' => [
                $path => ['nullable', 'array', 'min:1'],
                "{$path}.*" => ['string', Rule::in(array_keys(self::IMAGE_TYPES))],
            ],
            'content' => [$path => match ($type) {
                FormFieldType::Html => ['nullable', 'string', 'max:'.self::MAX_HTML_LENGTH],
                FormFieldType::Heading => ['nullable', 'string', 'max:255'],
                default => ['nullable', 'string', 'max:5000'],
            }],
            'heading_level' => [$path => ['nullable', Rule::in(['h2', 'h3', 'h4'])]],
            // Image block: a picture from the shared media library (page_media).
            'media_id' => [$path => ['required', 'integer', Rule::exists('page_media', 'id')]],
            'alt' => [$path => ['nullable', 'string', 'max:300']],
            'max_width' => [$path => ['nullable', 'integer', 'min:16', 'max:'.self::IMAGE_MAX_WIDTH]],
            'align' => [$path => ['nullable', Rule::in(['left', 'center', 'right'])]],
            'radius' => [$path => ['nullable', 'integer', 'min:0', 'max:200']],
            'link_url' => [$path => ['nullable', 'string', 'max:2000', PageBlockSettingsSchema::safeUrlRule()]],
            'new_tab' => [$path => ['nullable', 'boolean']],
            'text_align' => [$path => ['nullable', Rule::in(['left', 'center', 'right'])]],
        };
    }

    /**
     * Typed, whitelisted copy of already-validated settings. Blank strings
     * and nulls are dropped so stored JSON only holds meaningful values.
     *
     * @return array<string, mixed>
     */
    public static function normalize(FormFieldType $type, ?array $settings): array
    {
        $settings ??= [];
        $clean = [];

        foreach ($type->settingKeys() as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];

            $value = match ($key) {
                'width' => (int) $value,
                'show_in_list', 'disabled', 'read_only', 'new_tab' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'max_length', 'rows', 'max_size_kb', 'media_id', 'max_width', 'radius' => $value === null || $value === '' ? null : (int) $value,
                'alt', 'link_url' => $value === null ? null : trim((string) $value),
                'allowed_types' => array_values(array_intersect(array_keys(self::IMAGE_TYPES), (array) $value)),
                'options' => self::orderedOptions($value ?? []),
                'default_value' => $type === FormFieldType::Checkbox
                    ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    : ($value === null ? null : (string) $value),
                'min', 'max', 'step' => $value === null || $value === '' ? null : (string) $value,
                default => $value,
            };

            // Only meaningful values are stored: blanks, `false` flags and
            // the default 100% width are all implied by their absence.
            if ($value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }
            if ($key === 'width' && $value === 100) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * validated() rebuilds nested arrays in rule order, not input order;
     * the numeric keys still carry the admin's order, so sort by them.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function orderedOptions(array $options): array
    {
        ksort($options);

        return array_values(array_map(fn ($option) => [
            'label' => trim((string) $option['label']),
            'value' => trim((string) $option['value']),
        ], $options));
    }

    /** MIME types accepted by an image field (all supported types when unset). @return list<string> */
    public static function imageMimes(array $settings): array
    {
        $types = $settings['allowed_types'] ?? array_keys(self::IMAGE_TYPES);

        return array_values(array_intersect_key(self::IMAGE_TYPES, array_flip($types)));
    }

    /**
     * Largest media-library upload (KB) this server really accepts: the
     * package ceiling, lowered to PHP's upload_max_filesize / post_max_size
     * when those are smaller (cPanel hosts often allow only 2-8 MB).
     */
    public static function mediaUploadMaxKb(): int
    {
        $limits = [(int) config('formbuilder.uploads.max_kb', 10240)];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $kb = self::iniSizeToKb((string) ini_get($ini));
            if ($kb > 0) {
                // Leave room for the rest of the multipart body under post_max_size.
                $limits[] = $ini === 'post_max_size' ? max(1, $kb - 64) : $kb;
            }
        }

        return min($limits);
    }

    private static function iniSizeToKb(string $value): int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) {
            return 0; // unset / unlimited
        }
        $bytes = (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return intdiv($bytes, 1024);
    }

    public static function imageMaxKb(array $settings): int
    {
        return min((int) ($settings['max_size_kb'] ?? config('formbuilder.uploads.default_field_max_kb', 4096)), (int) config('formbuilder.uploads.max_kb', 10240));
    }
}
