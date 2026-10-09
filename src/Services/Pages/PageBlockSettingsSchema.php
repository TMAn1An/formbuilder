<?php

namespace TMAn1An\FormBuilder\Services\Pages;

use Closure;
use Illuminate\Validation\Rule;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\Styling\ButtonStyleSchema;
use TMAn1An\FormBuilder\Styling\StyleProperties;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * Validation + normalization of one page block's `settings`. Which keys a
 * type accepts comes from PageBlockType::settingKeys(); anything else in a
 * payload is dropped. Colors, sizes and enums are validated to safe CSS
 * values here, so the renderer can turn them into inline styles without
 * ever emitting admin free text into CSS. HTML content (Paragraph, Custom
 * HTML) is sanitized; plain text (Heading, Button) is escaped at render.
 */
final class PageBlockSettingsSchema
{
    use StyleProperties;

    public const IMAGE_LENGTH_MAX = ['px' => 4000, '%' => 100, 'rem' => 250];

    /** @return array<string, list<mixed>> */
    public static function rules(PageBlockType $type, string $prefix): array
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
    private static function rulesForKey(PageBlockType $type, string $key, string $path): array
    {
        $color = ['nullable', 'string', 'regex:'.self::COLOR_PATTERN];
        $int = fn (int $min, int $max) => ['nullable', 'integer', "min:{$min}", "max:{$max}"];
        $length = ['nullable', 'string', 'max:12', function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && $value !== '' && self::cleanLength(['max' => self::IMAGE_LENGTH_MAX], $value) === null) {
                $fail('Use a number followed by px, % or rem (e.g. 600px or 100%).');
            }
        }];

        return match ($key) {
            'background_color', 'text_color', 'color' => [$path => $color],
            'padding_y', 'margin_top', 'margin_bottom', 'margin_y' => [$path => $int(0, 160)],
            'padding_x' => [$path => $int(0, 120)],
            'radius' => [$path => $int(0, 200)],
            'count' => [$path => ['required', Rule::in([2, 3, 4])]],
            'gap' => [$path => $int(0, 80)],
            'vertical_align' => [$path => ['nullable', Rule::in(['start', 'center', 'end'])]],
            'text' => [$path => $type === PageBlockType::Button ? ['required', 'string', 'max:120'] : ['nullable', 'string', 'max:300']],
            'level' => [$path => ['nullable', Rule::in(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])]],
            'align' => [$path => ['nullable', Rule::in(['left', 'center', 'right'])]],
            'font_size' => [$path => $int(10, 120)],
            'font_weight' => [$path => ['nullable', Rule::in(['400', '500', '600', '700', '800', 400, 500, 600, 700, 800])]],
            'content' => [$path => ['nullable', 'string', 'max:20000']],
            'media_id' => [$path => ['required', 'integer', Rule::exists('page_media', 'id')]],
            'alt' => [$path => ['nullable', 'string', 'max:300']],
            'width', 'max_width' => [$path => $length],
            'link_url' => [$path => ['nullable', 'string', 'max:2000', self::safeUrlRule()]],
            'url' => [$path => ['required', 'string', 'max:2000', self::safeUrlRule()]],
            'new_tab', 'hide_title', 'hide_description' => [$path => ['nullable', 'boolean']],
            'thickness' => [$path => $int(1, 20)],
            'height' => [$path => $int(0, 400)],
            'form_id' => [$path => ['required', 'integer', Rule::exists('forms', 'id')]],
            'style' => ButtonStyleSchema::rules($path),
        };
    }

    /** http(s)/mailto/tel URLs, same-site paths ("/events") and in-page anchors ("#register") only. */
    public static function safeUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && $value !== '' && ! self::isSafeUrl($value)) {
                $fail('Links must start with https://, http://, mailto:, tel:, / or #.');
            }
        };
    }

    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s<>"]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '#') || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))) {
            return true;
        }

        return (bool) preg_match('#^(https?://[^/\s]+|mailto:[^\s]+|tel:[0-9+()\-. ]+)#i', $url);
    }

    /**
     * Typed, whitelisted copy of validated settings. Empty values are
     * dropped; HTML content is sanitized; `form_id` / `media_id` are pulled
     * out (they become real foreign-key columns, not JSON).
     *
     * @return array{settings: array<string, mixed>, form_id: int|null, media_id: int|null}
     */
    public static function normalize(PageBlockType $type, ?array $settings, HtmlSanitizer $html): array
    {
        $settings ??= [];
        $clean = [];
        $formId = null;
        $mediaId = null;

        foreach ($type->settingKeys() as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];

            if ($key === 'form_id') {
                $formId = $value === null ? null : (int) $value;

                continue;
            }
            if ($key === 'media_id') {
                $mediaId = $value === null ? null : (int) $value;

                continue;
            }

            $value = match ($key) {
                'padding_y', 'padding_x', 'radius', 'count', 'gap', 'font_size', 'margin_top', 'margin_bottom', 'margin_y', 'thickness', 'height' => $value === null || $value === '' ? null : (int) $value,
                'font_weight' => $value === null || $value === '' ? null : (string) $value,
                'background_color', 'text_color', 'color' => is_string($value) && $value !== '' ? strtolower($value) : null,
                'new_tab', 'hide_title', 'hide_description' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'width', 'max_width' => self::cleanLength(['max' => self::IMAGE_LENGTH_MAX], $value),
                'content' => $html->sanitize(is_string($value) ? $value : null),
                'text', 'alt', 'link_url', 'url' => is_string($value) ? trim($value) : null,
                'style' => ButtonStyleSchema::normalize(is_array($value) ? $value : null),
                default => $value,
            };

            if ($value === null || $value === '' || $value === false || $value === []) {
                continue;
            }
            $clean[$key] = $value;
        }

        if ($type === PageBlockType::Columns) {
            $clean['count'] ??= 2;
        }

        return ['settings' => $clean, 'form_id' => $formId, 'media_id' => $mediaId];
    }

    /** Number of child slots a container block has (0 for leaf blocks). */
    public static function slotCount(PageBlockType $type, array $settings): int
    {
        return match ($type) {
            PageBlockType::Section => 1,
            PageBlockType::Columns => in_array((int) ($settings['count'] ?? 2), [2, 3, 4], true) ? (int) ($settings['count'] ?? 2) : 2,
            default => 0,
        };
    }
}
