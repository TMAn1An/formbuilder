<?php

namespace TMAn1An\FormBuilder\Services\Pages;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Styling\PageStyleSchema;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * Validates and normalizes a complete Page Builder "save" payload BEFORE
 * anything is written (it runs inside the Form Request, ahead of the
 * controller). See docs/PAGES.md §Save payload.
 *
 * The payload is a FLAT, ordered list of blocks:
 *
 *   {ref, id|null, parent: <ref>|null, column, order, type, settings{}}
 *
 *   ref     client reference, unique within the payload. New blocks use a
 *           temporary ref ("n7"); it is never trusted as a database id.
 *   id      the persisted block id, or null for a new block. Validated
 *           separately: it must be one of THIS page's current blocks and
 *           may appear only once.
 *   parent  the ref of the container this block sits in (null = top level).
 *   column  slot inside the parent (0-based; < the parent's column count).
 *   order   position among its siblings (normalized; ties keep input order).
 *
 * Structural invariants enforced (each gets an explicit error, nothing is
 * silently ignored): unique refs; unique persisted ids; persisted ids must
 * belong to this page and still exist (a removed block can't be resurrected
 * by a stale payload); no orphans (parent ref must exist); a block can't
 * be its own parent; only containers (section, columns) have children;
 * exactly one level of nesting (a child can't have children, a container
 * can't sit inside a container); child column within the parent's
 * columns; the nested "children" key is not accepted at all.
 */
final class PageDefinitionValidator
{
    public const MAX_BLOCKS = 300;

    public const REF_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    public function __construct(private readonly HtmlSanitizer $html) {}

    /** @return array<string, list<mixed>> */
    public function rules(Page $page, mixed $blocks, bool $canManageCustomCode): array
    {
        $rules = [
            'version' => ['required', 'integer'],
            'intent' => ['nullable', Rule::in(['save', 'publish'])],
            'autosave' => ['nullable', 'boolean'],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'settings' => ['nullable', 'array'],
            'settings.meta_description' => ['nullable', 'string', 'max:300'],
            // Rejected server-side for anyone without custom-code access,
            // however the request was crafted.
            'custom_css' => [$canManageCustomCode ? 'nullable' : 'prohibited', 'string', 'max:'.CssScoper::MAX_LENGTH],
            'blocks' => ['present', 'array', 'max:'.self::MAX_BLOCKS],
            'blocks.*' => ['array'],
            'blocks.*.ref' => ['required', 'string', 'regex:'.self::REF_PATTERN],
            'blocks.*.id' => ['nullable', 'integer', 'min:1'],
            'blocks.*.parent' => ['nullable', 'string', 'regex:'.self::REF_PATTERN],
            'blocks.*.column' => ['nullable', 'integer', 'min:0', 'max:3'],
            'blocks.*.order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'blocks.*.type' => ['required', Rule::enum(PageBlockType::class)],
            'blocks.*.children' => ['prohibited'],
        ];

        $rules += PageStyleSchema::rules();

        foreach (is_array($blocks) ? $blocks : [] as $index => $block) {
            $type = is_array($block) ? PageBlockType::tryFrom((string) ($block['type'] ?? '')) : null;
            if ($type !== null) {
                $rules += PageBlockSettingsSchema::rules($type, "blocks.{$index}.settings");
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'custom_css.prohibited' => 'Only an administrator with custom-code access can change custom CSS.',
            'blocks.*.children.prohibited' => 'Nested "children" are not accepted; reference the parent block with "parent" instead.',
            'blocks.*.ref.regex' => 'Block references may only use letters, numbers, "-" and "_" (max 40).',
            'blocks.*.parent.regex' => 'Block references may only use letters, numbers, "-" and "_" (max 40).',
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }

    /** Structural checks that per-key rules can't express. */
    public function after(Validator $validator, Page $page, array $data): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return; // structural errors first; these checks assume well-formed blocks
        }

        $blocks = $data['blocks'] ?? [];
        $errors = $validator->errors();

        // 1. Unique client refs.
        $indexByRef = [];
        foreach ($blocks as $i => $block) {
            if (isset($indexByRef[$block['ref']])) {
                $errors->add("blocks.{$i}.ref", "The block reference \"{$block['ref']}\" is used more than once.");
            }
            $indexByRef[$block['ref']] ??= $i;
        }

        // 2. Persisted ids: unique, and each one a CURRENT block of THIS page.
        $ids = collect($blocks)->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $current = $page->blocks()->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $elsewhere = PageBlock::query()->whereIn('id', $ids->all())->where('page_id', '!=', $page->id)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $seenIds = [];
        foreach ($blocks as $i => $block) {
            if (empty($block['id'])) {
                continue;
            }
            $id = (int) $block['id'];
            if (isset($seenIds[$id])) {
                $errors->add("blocks.{$i}.id", "Block #{$id} appears more than once; a block can only be in one place.");
            } elseif (isset($elsewhere[$id])) {
                $errors->add("blocks.{$i}.id", "Block #{$id} belongs to a different page.");
            } elseif (! isset($current[$id])) {
                $errors->add("blocks.{$i}.id", "Block #{$id} no longer exists on this page (it was removed or the builder is out of date). Reload the builder.");
            }
            $seenIds[$id] = true;
        }

        // 3. Parent/child structure.
        foreach ($blocks as $i => $block) {
            $type = PageBlockType::from($block['type']);
            $parentRef = $block['parent'] ?? null;

            if ($parentRef === null) {
                continue;
            }
            if ($parentRef === $block['ref']) {
                $errors->add("blocks.{$i}.parent", 'A block cannot be its own parent.');

                continue;
            }
            if (! isset($indexByRef[$parentRef])) {
                $errors->add("blocks.{$i}.parent", "Parent block \"{$parentRef}\" does not exist in this page (orphan block).");

                continue;
            }

            $parent = $blocks[$indexByRef[$parentRef]];
            $parentType = PageBlockType::from($parent['type']);

            if (! $parentType->isContainer()) {
                $errors->add("blocks.{$i}.parent", "A {$parentType->label()} block cannot contain other blocks.");
            } elseif (($parent['parent'] ?? null) !== null) {
                $errors->add("blocks.{$i}.parent", 'Only one level of nesting is allowed: a block inside a container cannot have children of its own.');
            } elseif ($type->isContainer()) {
                $errors->add("blocks.{$i}.type", "A {$type->label()} cannot be placed inside another container.");
            } else {
                $slots = PageBlockSettingsSchema::slotCount($parentType, $parent['settings'] ?? []);
                $column = (int) ($block['column'] ?? 0);
                if ($column < 0 || $column >= $slots) {
                    $errors->add("blocks.{$i}.column", "Column {$column} does not exist in its parent (it has {$slots} ".($slots === 1 ? 'column' : 'columns').').');
                }
            }
        }

        // 4. Form blocks must reference a form that may be embedded.
        $formIds = collect($blocks)->where('type', PageBlockType::Form->value)->pluck('settings.form_id')->filter()->all();
        $archived = Form::query()->whereIn('id', $formIds)->where('status', FormStatus::Archived)->pluck('id')->flip();
        foreach ($blocks as $i => $block) {
            $formId = $block['settings']['form_id'] ?? null;
            if ($block['type'] === PageBlockType::Form->value && $formId !== null && isset($archived[(int) $formId])) {
                $errors->add("blocks.{$i}.settings.form_id", 'Archived forms cannot be embedded.');
            }
        }

        if (($data['intent'] ?? 'save') === 'publish' && $blocks === []) {
            $errors->add('blocks', 'Add at least one block before publishing.');
        }
    }

    /**
     * Rebuilds the definition from whitelisted keys only, in render order:
     * every top-level block (by order, then input position) immediately
     * followed by its children (by column, then order, then input position).
     *
     * @param  array<string, mixed>  $data  validated payload
     * @return array<string, mixed>
     */
    public function normalize(array $data, bool $canManageCustomCode): array
    {
        // validated() rebuilds arrays in rule order -- restore input order.
        $input = $data['blocks'] ?? [];
        ksort($input);

        $items = [];
        foreach (array_values($input) as $position => $block) {
            $type = PageBlockType::from($block['type']);
            $clean = PageBlockSettingsSchema::normalize($type, $block['settings'] ?? null, $this->html);
            $items[] = [
                'ref' => (string) $block['ref'],
                'id' => empty($block['id']) ? null : (int) $block['id'],
                'parent' => $block['parent'] ?? null,
                'column' => isset($block['parent']) ? (int) ($block['column'] ?? 0) : 0,
                'order' => (int) ($block['order'] ?? $position),
                'position' => $position,
                'type' => $type,
                'settings' => $clean['settings'],
                'form_id' => $clean['form_id'],
                'media_id' => $clean['media_id'],
            ];
        }

        $byOrder = fn (array $a, array $b) => [$a['column'], $a['order'], $a['position']] <=> [$b['column'], $b['order'], $b['position']];
        $roots = array_values(array_filter($items, fn ($b) => $b['parent'] === null));
        usort($roots, $byOrder);

        $ordered = [];
        foreach ($roots as $root) {
            $ordered[] = $root;
            $children = array_values(array_filter($items, fn ($b) => $b['parent'] === $root['ref']));
            usort($children, $byOrder);
            array_push($ordered, ...$children);
        }

        $definition = [
            'title' => trim($data['title']),
            'slug' => $data['slug'],
            'settings' => ['meta_description' => ($data['settings']['meta_description'] ?? null) ?: null],
            'style_settings' => PageStyleSchema::normalize($data['style_settings'] ?? null),
            'blocks' => $ordered,
        ];

        if ($canManageCustomCode) {
            $definition['custom_css'] = ($data['custom_css'] ?? null) ?: null;
        }

        return $definition;
    }
}
