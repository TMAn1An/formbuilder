<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Styling\FieldStyleSchema;
use TMAn1An\FormBuilder\Styling\FormStyleSchema;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * The JSON the builder (public/js/admin/form-builder.js) boots from, and
 * the fresh copy it receives after every save. Schema/meta come straight
 * from the server-side enums and schemas, so the builder can never offer a
 * setting the server would reject.
 *
 * Raw custom code is only included for users allowed to edit it; everyone
 * gets the already-scoped/sanitized `rendered` versions so the live preview
 * still matches what visitors will see.
 */
class FormBuilderState
{
    public function __construct(
        private readonly FormDefinitionValidator $definitions,
        private readonly HtmlSanitizer $html,
        private readonly CssScoper $css,
    ) {}

    /** @return array<string, mixed> */
    public function forForm(Form $form, Authenticatable $user): array
    {
        $canCode = Gate::forUser($user)->allows('manageCustomCode', Form::class);
        $withSubmissions = $this->definitions->fieldIdsWithSubmissions($form);
        $fields = $form->fields()->get();

        return [
            'form' => [
                'id' => $form->id,
                'name' => $form->name,
                'slug' => $form->slug,
                'description' => $form->description,
                'status' => $form->status->value,
                'status_label' => $form->status->label(),
                'version' => $form->lock_version,
                'settings' => FormSettingsSchema::normalize($form->settings),
                'style_settings' => FormStyleSchema::normalize($form->style_settings),
                'custom_css' => $canCode ? $form->custom_css : null,
                'custom_html_before' => $canCode ? $form->custom_html_before : null,
                'custom_html_after' => $canCode ? $form->custom_html_after : null,
                'custom_js' => $canCode ? $form->custom_js : null,
                'has_custom_code' => filled($form->custom_css) || filled($form->custom_html_before) || filled($form->custom_html_after) || filled($form->custom_js),
            ],
            'fields' => $fields->map(fn (FormField $field) => [
                'id' => $field->id,
                'type' => $field->type->value,
                'label' => $field->label,
                'key' => $field->key,
                'required' => $field->required,
                'is_active' => $field->is_active,
                'settings' => (object) ($field->settings ?? []),
                'style_settings' => (object) ($field->style_settings ?? []),
                'conditional_rules' => $field->conditional_rules,
                'has_submissions' => isset($withSubmissions[$field->id]),
            ])->values()->all(),
            'rendered' => [
                'css' => $this->css->scope($form->custom_css, '#'.$form->wrapperId()),
                'html_before' => $this->html->sanitize($form->custom_html_before),
                'html_after' => $this->html->sanitize($form->custom_html_after),
                'fields' => (object) $fields
                    ->filter(fn (FormField $f) => $f->type === FormFieldType::Html)
                    ->mapWithKeys(fn (FormField $f) => [$f->id => $this->html->sanitize($f->setting('content'))])
                    ->all(),
            ],
            'media' => $this->media($form, $fields),
            'permissions' => [
                'canEdit' => Gate::forUser($user)->allows('update', $form),
                'canManageCustomCode' => $canCode,
            ],
        ];
    }

    /**
     * The media library for the builder's image pickers: the latest uploads
     * plus every image this form already uses (even an older one), so a
     * saved banner or Image block always shows its own image.
     *
     * @param  Collection<int, FormField>  $fields
     * @return list<array<string, mixed>>
     */
    private function media(Form $form, $fields): array
    {
        $used = $fields->map(fn (FormField $f) => $f->type === FormFieldType::ImageBlock ? $f->setting('media_id') : null)
            ->push(FormSettingsSchema::normalize($form->settings)['banner_media_id'])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return PageMedia::query()->latest('id')->limit(200)->get()
            ->merge(PageMedia::query()->whereIn('id', $used)->get())
            ->unique('id')->sortByDesc('id')
            ->map(fn (PageMedia $m) => $m->toBuilderArray())->values()->all();
    }

    /** Static schema the builder renders its palette and panels from. @return array<string, mixed> */
    public function meta(): array
    {
        return [
            'types' => FormFieldType::builderMeta(),
            'formStyle' => FormStyleSchema::describeForBuilder(),
            'fieldStyle' => FieldStyleSchema::describeForBuilder(),
            'widths' => FieldSettingsSchema::WIDTHS,
            'operators' => [
                'equals' => 'equals',
                'not_equals' => 'does not equal',
                'contains' => 'contains',
                'is_empty' => 'is empty',
                'is_not_empty' => 'is not empty',
            ],
            'keyPattern' => '^[a-z][a-z0-9_]{0,63}$',
            'uploads' => [
                'maxKb' => (int) config('formbuilder.uploads.max_kb', 10240),
                'defaultKb' => (int) config('formbuilder.uploads.default_field_max_kb', 4096),
                'mediaMaxKb' => FieldSettingsSchema::mediaUploadMaxKb(),
            ],
            'imageMaxWidth' => FieldSettingsSchema::IMAGE_MAX_WIDTH,
            'banner' => ['minHeight' => FormSettingsSchema::BANNER_MIN_HEIGHT, 'maxHeight' => FormSettingsSchema::BANNER_MAX_HEIGHT],
        ];
    }
}
