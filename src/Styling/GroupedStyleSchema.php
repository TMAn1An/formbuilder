<?php

namespace TMAn1An\FormBuilder\Styling;

/**
 * Base for design schemas organised in groups (form design, page design):
 * defaults, validation rules, normalization and CSS custom-property output
 * all derive from one static::definition().
 */
abstract class GroupedStyleSchema
{
    use StyleProperties;

    /** @return array<string, array{label: string, properties: array<string, array<string, mixed>>}> */
    abstract public static function definition(): array;

    /** @return array<string, array<string, string|int>> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (static::definition() as $group => $groupDef) {
            foreach ($groupDef['properties'] as $key => $def) {
                $defaults[$group][$key] = $def['default'];
            }
        }

        return $defaults;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = 'style_settings'): array
    {
        $rules = [$prefix => ['nullable', 'array']];
        foreach (static::definition() as $group => $groupDef) {
            $rules["{$prefix}.{$group}"] = ['nullable', 'array'];
            foreach ($groupDef['properties'] as $key => $def) {
                $rules["{$prefix}.{$group}.{$key}"] = static::rulesFor($def);
            }
        }

        return $rules;
    }

    /**
     * Known keys only, invalid values replaced by defaults. Used both when
     * saving (after validation) and when rendering (defense in depth: a
     * value that somehow reached the DB unvalidated still never reaches CSS).
     *
     * @return array<string, array<string, string|int>>
     */
    public static function normalize(?array $styles): array
    {
        $normalized = [];
        foreach (static::definition() as $group => $groupDef) {
            foreach ($groupDef['properties'] as $key => $def) {
                $normalized[$group][$key] = static::cleanValue($def, $styles[$group][$key] ?? null) ?? $def['default'];
            }
        }

        return $normalized;
    }

    /** `--x:#fff;--y:32px;...` for the wrapper element. */
    public static function cssDeclarations(?array $styles): string
    {
        $normalized = static::normalize($styles);
        $declarations = [];
        foreach (static::definition() as $group => $groupDef) {
            foreach ($groupDef['properties'] as $key => $def) {
                $declarations[] = $def['var'].':'.static::cssValue($def, $normalized[$group][$key], $normalized[$group]);
            }
        }

        return implode(';', $declarations);
    }

    /** @return array<string, mixed> */
    public static function describeForBuilder(): array
    {
        return static::definition();
    }
}
