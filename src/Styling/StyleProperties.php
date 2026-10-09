<?php

namespace TMAn1An\FormBuilder\Styling;

use Illuminate\Validation\Rule;

/**
 * Shared mechanics for every style schema (form design, per-field
 * overrides, buttons, page design). A style property is one of four
 * kinds, and ONLY these kinds -- nothing an admin types ever reaches a
 * stylesheet as free text:
 *
 *   color  -- `#rrggbb` only (what <input type="color"> produces)
 *   px     -- integer within min..max, emitted as `{n}px`
 *   enum   -- a key from a fixed map; the CSS emitted is the map's
 *             server-side value, never the submitted string. A map value
 *             of "@other_key" means "use the (validated) length stored in
 *             other_key, capped at 100% of the container" -- used for
 *             button width "custom".
 *   length -- `{number}{unit}` with a whitelisted unit (px, %, rem) and a
 *             per-unit maximum, e.g. `220px`, `50%`, `12rem`
 *
 * Every property is emitted as a CSS custom property that the shared
 * stylesheets consume, so builder previews and public pages render
 * through exactly the same CSS.
 */
trait StyleProperties
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public const LENGTH_PATTERN = '/^(\d{1,4}(?:\.\d{1,2})?)(px|%|rem)$/';

    /** @param  array<string, mixed>  $def
     * @return list<mixed> */
    protected static function rulesFor(array $def): array
    {
        return match ($def['type']) {
            'color' => ['nullable', 'string', 'regex:'.self::COLOR_PATTERN],
            'px' => ['nullable', 'integer', 'min:'.$def['min'], 'max:'.$def['max']],
            'enum' => ['nullable', 'string', Rule::in(array_map('strval', array_keys($def['options'])))],
            'length' => ['nullable', 'string', 'max:12', function (string $attribute, mixed $value, \Closure $fail) use ($def) {
                if ($value !== null && $value !== '' && self::cleanLength($def, $value) === null) {
                    $fail('Use a number followed by px, % or rem within the allowed range (e.g. 220px, 50%, 12rem).');
                }
            }],
        };
    }

    /** Returns the cleaned value, or null when the value is not acceptable. @param  array<string, mixed>  $def */
    protected static function cleanValue(array $def, mixed $value): string|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($def['type']) {
            'color' => is_string($value) && preg_match(self::COLOR_PATTERN, $value) ? strtolower($value) : null,
            'px' => is_numeric($value) && (int) $value == $value && $value >= $def['min'] && $value <= $def['max'] ? (int) $value : null,
            'enum' => (is_string($value) || is_int($value)) && array_key_exists((string) $value, $def['options']) ? (string) $value : null,
            'length' => self::cleanLength($def, $value),
        };
    }

    /** @param  array<string, mixed>  $def */
    protected static function cleanLength(array $def, mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match(self::LENGTH_PATTERN, trim($value), $m)) {
            return null;
        }
        $max = $def['max'][$m[2]] ?? null;
        if ($max === null || (float) $m[1] > $max) {
            return null;
        }

        return ((float) $m[1] == (int) $m[1] ? (string) (int) $m[1] : rtrim(rtrim($m[1], '0'), '.')).$m[2];
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $siblings  cleaned values of the other properties in the same group
     */
    protected static function cssValue(array $def, string|int $value, array $siblings = []): string
    {
        return match ($def['type']) {
            'color', 'length' => (string) $value,
            'px' => $value.'px',
            'enum' => self::enumCss($def['options'][(string) $value]['css'], $siblings),
        };
    }

    private static function enumCss(string $css, array $siblings): string
    {
        if (! str_starts_with($css, '@')) {
            return $css;
        }
        $length = $siblings[substr($css, 1)] ?? null;

        return is_string($length) && $length !== '' ? 'min('.$length.', 100%)' : 'auto';
    }
}
