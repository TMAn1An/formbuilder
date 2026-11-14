<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use Illuminate\Support\Collection;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Services\Pages\PageBlockSettingsSchema;
use TMAn1An\FormBuilder\Styling\FieldStyleSchema;
use TMAn1An\FormBuilder\Styling\FormStyleSchema;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * Assembles everything the renderer partial (resources/views/forms/
 * _renderer.blade.php) needs, so the Blade stays presentation-only:
 *   - the form's generated design CSS + its scoped custom CSS,
 *   - sanitized custom HTML / HTML-block content (sanitized again here at
 *     render time, on top of the save-time pass),
 *   - per-field inline style overrides and width classes,
 *   - the banner and Image-block pictures (media library URLs/dimensions),
 *   - the conditional-logic JSON consumed by form-runtime.js.
 */
class FormPresenter
{
    public function __construct(
        private readonly HtmlSanitizer $html,
        private readonly CssScoper $css,
    ) {}

    /** @return array<string, mixed> */
    public function present(Form $form): array
    {
        $fields = $form->activeFields()->get();
        $scope = '#'.$form->wrapperId();
        $settings = FormSettingsSchema::normalize($form->settings);
        // One query for every picture on the form (banner + Image blocks).
        $media = PageMedia::query()->whereIn('id', $fields
            ->map(fn (FormField $f) => $f->type === FormFieldType::ImageBlock ? (int) $f->setting('media_id') : null)
            ->push($settings['banner_media_id'])->filter()->unique()->values()->all())
            ->get()->keyBy('id');

        return [
            'form' => $form,
            'wrapperId' => $form->wrapperId(),
            // Values are validated, enum-mapped CSS (never free text); the
            // custom CSS is scoped and has `<` escaped -- see CssScoper.
            'styleSheet' => trim($scope.'{'.FormStyleSchema::cssDeclarations($form->style_settings).'}'."\n".$this->css->scope($form->custom_css, $scope)),
            'htmlBefore' => $this->html->sanitize($form->custom_html_before),
            'htmlAfter' => $this->html->sanitize($form->custom_html_after),
            'banner' => $this->banner($settings, $media),
            'fields' => $fields->map(fn (FormField $field) => $this->presentField($form, $field, $media))->all(),
            'logic' => $fields->map(fn (FormField $field) => [
                'key' => $field->key,
                'type' => $field->type->value,
                'collectsValue' => $field->type->collectsValue(),
                'rules' => $field->conditional_rules,
            ])->values()->all(),
        ];
    }

    /**
     * The banner above the title, or null when none is set (or its image is
     * gone). The height setting is a maximum: the image keeps its own shape
     * until it would be taller than that, then it is cropped to fit (so a
     * phone sees the whole banner, just smaller).
     *
     * @param  Collection<int, PageMedia>  $media
     * @return array{url: string, alt: string, width: ?int, height: ?int, style: string}|null
     */
    private function banner(array $settings, $media): ?array
    {
        $image = $settings['banner_media_id'] ? $media->get($settings['banner_media_id']) : null;
        if (! $image) {
            return null;
        }
        $height = $settings['banner_height'];

        return [
            'url' => $image->url(),
            'alt' => (string) ($settings['banner_alt'] ?? ''),
            'width' => $image->width,
            'height' => $image->height,
            // Validated integer, not free text -- safe to emit as a style value.
            'style' => $height ? 'max-height:'.(int) $height.'px;object-fit:cover' : '',
        ];
    }

    /**
     * An Image block's picture, or null when it has no (existing) image.
     *
     * @param  Collection<int, PageMedia>  $media
     * @return array{url: string, alt: string, width: ?int, height: ?int, style: string, align: string, link: ?string, newTab: bool}|null
     */
    private function picture(FormField $field, $media): ?array
    {
        $image = $media->get((int) $field->setting('media_id'));
        if (! $image) {
            return null;
        }
        $style = [];
        if ($maxWidth = (int) $field->setting('max_width')) {
            $style[] = 'max-width:min(100%,'.min($maxWidth, FieldSettingsSchema::IMAGE_MAX_WIDTH).'px)';
        }
        if ($radius = (int) $field->setting('radius')) {
            $style[] = 'border-radius:'.min($radius, 200).'px';
        }
        $link = (string) $field->setting('link_url', '');

        return [
            'url' => $image->url(),
            'alt' => (string) $field->setting('alt', ''),
            'width' => $image->width,
            'height' => $image->height,
            'style' => implode(';', $style),
            // Re-checked: emitted into a style attribute and an href.
            'align' => in_array($field->setting('align'), ['center', 'right'], true) ? $field->setting('align') : 'left',
            'link' => $link !== '' && PageBlockSettingsSchema::isSafeUrl($link) ? $link : null,
            'newTab' => (bool) $field->setting('new_tab'),
        ];
    }

    /**
     * @param  Collection<int, PageMedia>  $media
     * @return array<string, mixed>
     */
    private function presentField(Form $form, FormField $field, $media): array
    {
        $width = (int) $field->setting('width', 100);

        return [
            'model' => $field,
            'type' => $field->type,
            'key' => $field->key,
            'inputId' => 'ff-'.$form->id.'-'.$field->key,
            'name' => $field->type->isMultiValue() ? $field->key.'[]' : $field->key,
            'widthClass' => $width === 100 ? '' : 'ff-w-'.$width,
            'cssClass' => (string) $field->setting('css_class', ''),
            'inlineStyle' => FieldStyleSchema::cssDeclarations($field->style_settings),
            'html' => $field->type === FormFieldType::Html ? $this->html->sanitize($field->setting('content')) : null,
            'picture' => $field->type === FormFieldType::ImageBlock ? $this->picture($field, $media) : null,
            // Re-checked against their enums here because both are emitted
            // into markup (a tag name, a style value), not escaped text.
            'headingLevel' => in_array($field->setting('heading_level'), ['h2', 'h3', 'h4'], true) ? $field->setting('heading_level') : 'h3',
            'textAlign' => in_array($field->setting('text_align'), ['left', 'center', 'right'], true) ? $field->setting('text_align') : null,
        ];
    }
}
