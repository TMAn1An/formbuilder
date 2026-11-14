<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use Illuminate\Validation\Rule;

/**
 * Behavioral form settings (forms.settings JSON): display title, submit
 * text, success message/redirect, submission policy and schedule, and the
 * optional banner: an image from the shared media library (page_media),
 * shown as its own section above or below the form card, either as wide as
 * the form or the full page width (set from the builder's Design tab), and
 * `multi_step`: show one section at a time with Next/Back (every Section
 * field starts a step; done in the browser by form-runtime.js, so the server
 * still receives and validates the whole form in one submission).
 * `schema_version` is stamped server-side on every save so a future change
 * to this JSON shape can be migrated deliberately instead of guessed at.
 */
final class FormSettingsSchema
{
    public const SCHEMA_VERSION = 1;

    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    public const BANNER_MIN_HEIGHT = 60;

    public const BANNER_MAX_HEIGHT = 600;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'title' => null,
            'submit_label' => 'Submit',
            'success_message' => 'Thank you! Your response has been recorded.',
            'redirect_url' => null,
            'allow_multiple_submissions' => true,
            'visibility' => 'public',
            'submission_limit' => null,
            'opens_at' => null,
            'closes_at' => null,
            'banner_media_id' => null,
            'banner_alt' => null,
            'banner_height' => null,
            'banner_position' => 'top',
            'banner_width' => 'form',
            'multi_step' => false,
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = 'settings'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.title" => ['nullable', 'string', 'max:200'],
            "{$prefix}.submit_label" => ['nullable', 'string', 'max:80'],
            "{$prefix}.success_message" => ['nullable', 'string', 'max:2000'],
            // http(s) only -- never javascript:/data: etc.
            "{$prefix}.redirect_url" => ['nullable', 'string', 'max:2000', 'url:http,https'],
            "{$prefix}.allow_multiple_submissions" => ['nullable', 'boolean'],
            "{$prefix}.visibility" => ['nullable', Rule::in(['public', 'private'])],
            "{$prefix}.submission_limit" => ['nullable', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}.opens_at" => ['nullable', 'date_format:'.self::DATETIME_FORMAT],
            // "closes after opens" is checked in FormDefinitionValidator::after()
            // -- Laravel's after:<field> fails outright when that field is empty.
            "{$prefix}.closes_at" => ['nullable', 'date_format:'.self::DATETIME_FORMAT],
            "{$prefix}.banner_media_id" => ['nullable', 'integer', Rule::exists('page_media', 'id')],
            "{$prefix}.banner_alt" => ['nullable', 'string', 'max:300'],
            // Maximum banner height in px (taller images are cropped to fit); empty = the image's own proportions.
            "{$prefix}.multi_step" => ['nullable', 'boolean'],
            "{$prefix}.banner_position" => ['nullable', Rule::in(['top', 'bottom'])],
            "{$prefix}.banner_width" => ['nullable', Rule::in(['form', 'full'])],
            "{$prefix}.banner_height" => ['nullable', 'integer', 'min:'.self::BANNER_MIN_HEIGHT, 'max:'.self::BANNER_MAX_HEIGHT],
        ];
    }

    /** Whitelisted, typed copy of already-validated input. @return array<string, mixed> */
    public static function normalize(?array $settings): array
    {
        $settings ??= [];
        $defaults = self::defaults();
        $blankToNull = fn ($value) => is_string($value) && trim($value) === '' ? null : $value;

        return [
            'title' => $blankToNull($settings['title'] ?? null),
            'submit_label' => $blankToNull($settings['submit_label'] ?? null) ?? $defaults['submit_label'],
            'success_message' => $blankToNull($settings['success_message'] ?? null) ?? $defaults['success_message'],
            'redirect_url' => $blankToNull($settings['redirect_url'] ?? null),
            'allow_multiple_submissions' => filter_var($settings['allow_multiple_submissions'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'visibility' => ($settings['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'submission_limit' => isset($settings['submission_limit']) && $settings['submission_limit'] !== '' ? (int) $settings['submission_limit'] : null,
            'opens_at' => $blankToNull($settings['opens_at'] ?? null),
            'closes_at' => $blankToNull($settings['closes_at'] ?? null),
            'banner_media_id' => isset($settings['banner_media_id']) && $settings['banner_media_id'] !== '' ? (int) $settings['banner_media_id'] : null,
            'banner_alt' => is_string($settings['banner_alt'] ?? null) ? $blankToNull(trim($settings['banner_alt'])) : null,
            'banner_height' => isset($settings['banner_height']) && $settings['banner_height'] !== '' ? (int) $settings['banner_height'] : null,
            'banner_position' => ($settings['banner_position'] ?? 'top') === 'bottom' ? 'bottom' : 'top',
            'banner_width' => ($settings['banner_width'] ?? 'form') === 'full' ? 'full' : 'form',
            'multi_step' => filter_var($settings['multi_step'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }
}
