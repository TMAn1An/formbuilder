<?php

namespace TMAn1An\FormBuilder\Services\Pages;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Services\Forms\FieldSettingsSchema;
use TMAn1An\FormBuilder\Styling\ButtonStyleSchema;
use TMAn1An\FormBuilder\Styling\PageStyleSchema;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * The JSON the page builder (resources/dist/js/page-builder.js) boots from
 * and receives after each save -- in exactly the flat {ref, id, parent,
 * column, type, settings} shape PageDefinitionValidator accepts back.
 */
class PageBuilderState
{
    public function __construct(
        private readonly HtmlSanitizer $html,
        private readonly CssScoper $css,
    ) {}

    /** @return array<string, mixed> */
    public function forPage(Page $page, Authenticatable $user): array
    {
        $canCode = Gate::forUser($user)->allows('manageCustomCode', Page::class);
        $blocks = $page->blocks()->get();

        return [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status->value,
                'status_label' => $page->status->label(),
                'version' => $page->lock_version,
                'settings' => (object) ($page->settings ?? []),
                'style_settings' => PageStyleSchema::normalize($page->style_settings),
                'custom_css' => $canCode ? $page->custom_css : null,
                'has_custom_code' => filled($page->custom_css),
            ],
            'blocks' => $blocks->map(fn (PageBlock $b) => [
                'ref' => 'b'.$b->id,
                'id' => $b->id,
                'parent' => $b->parent_id !== null ? 'b'.$b->parent_id : null,
                'column' => $b->slot,
                'type' => $b->type->value,
                'settings' => (object) array_filter(
                    ($b->settings ?? []) + ['form_id' => $b->form_id, 'media_id' => $b->media_id],
                    fn ($v) => $v !== null,
                ),
            ])->values()->all(),
            'rendered' => [
                'css' => $this->css->scope($page->custom_css, '#'.$page->wrapperId()),
                'html' => (object) $blocks
                    ->filter(fn (PageBlock $b) => in_array($b->type, [PageBlockType::Paragraph, PageBlockType::Html], true))
                    ->mapWithKeys(fn (PageBlock $b) => ['b'.$b->id => $this->html->sanitize($b->setting('content'))])
                    ->all(),
            ],
            'media' => PageMedia::query()->latest('id')->limit(200)->get()->map(fn (PageMedia $m) => $this->media($m))->all(),
            'forms' => Form::query()->where('status', '!=', FormStatus::Archived)->orderBy('name')->get(['id', 'name', 'status', 'slug'])
                ->map(fn (Form $f) => ['id' => $f->id, 'name' => $f->name, 'status' => $f->status->value, 'status_label' => $f->status->label()])->all(),
            'permissions' => [
                'canEdit' => Gate::forUser($user)->allows('update', $page),
                'canManageCustomCode' => $canCode,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function media(PageMedia $media): array
    {
        return $media->toBuilderArray();
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return [
            'types' => PageBlockType::builderMeta(),
            'pageStyle' => PageStyleSchema::describeForBuilder(),
            'buttonStyle' => ButtonStyleSchema::properties(),
            'imageLengthMax' => PageBlockSettingsSchema::IMAGE_LENGTH_MAX,
            'refPattern' => '^[A-Za-z0-9_-]{1,40}$',
            // What the server really accepts (PHP's limits included), so the label is honest.
            'uploads' => ['maxKb' => FieldSettingsSchema::mediaUploadMaxKb()],
        ];
    }
}
