<?php

namespace TMAn1An\FormBuilder\Services\Pages;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TMAn1An\FormBuilder\Contracts\AuditLogger;
use TMAn1An\FormBuilder\Enums\AuditEvent;
use TMAn1An\FormBuilder\Enums\PageStatus;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Services\Forms\FormVersionConflictException;
use TMAn1An\FormBuilder\Styling\PageStyleSchema;

/**
 * Every write to a page's definition or lifecycle. A save is ONE
 * transaction with the page row locked: blocks are upserted top-level
 * first (so children can resolve their parent's id), then blocks missing
 * from the payload are deleted, then the page row is updated. Any failure
 * rolls the whole save back -- a page is never left half-saved.
 * The definition has already been fully validated by
 * PageDefinitionValidator before this runs.
 */
class PageBuilderService
{
    /** @var array<string, int> client ref => block id of the last successful save */
    private array $lastIdsByRef = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, int> */
    public function lastIdsByRef(): array
    {
        return $this->lastIdsByRef;
    }

    public function create(string $title, Authenticatable $actor): Page
    {
        return DB::transaction(function () use ($title, $actor) {
            $page = Page::create([
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'status' => PageStatus::Draft,
                'settings' => ['meta_description' => null],
                'style_settings' => PageStyleSchema::defaults(),
                'lock_version' => 1,
                'created_by' => $actor->getAuthIdentifier(),
                'updated_by' => $actor->getAuthIdentifier(),
            ]);

            $this->audit->record(AuditEvent::PageCreated, $page, $actor, "Created page \"{$page->title}\".");

            return $page;
        });
    }

    /**
     * @param  array<string, mixed>  $definition  PageDefinitionValidator::normalize() output
     *
     * @throws FormVersionConflictException when $expectedVersion is stale
     */
    public function saveDefinition(Page $page, array $definition, int $expectedVersion, Authenticatable $actor, bool $publish = false, bool $autosave = false): Page
    {
        return DB::transaction(function () use ($page, $definition, $expectedVersion, $actor, $publish, $autosave) {
            /** @var Page $locked */
            $locked = Page::query()->lockForUpdate()->findOrFail($page->id);

            if ($locked->lock_version !== $expectedVersion) {
                throw new FormVersionConflictException($locked->lock_version);
            }

            $existing = $locked->blocks()->get()->keyBy('id');
            $idByRef = [];
            $kept = [];

            // Normalized order guarantees every parent precedes its children.
            foreach ($definition['blocks'] as $sortOrder => $block) {
                $attributes = [
                    'parent_id' => $block['parent'] !== null ? $idByRef[$block['parent']] : null,
                    'slot' => $block['column'],
                    'type' => $block['type'],
                    'sort_order' => $sortOrder + 1,
                    'settings' => $block['settings'],
                    'form_id' => $block['form_id'],
                    'media_id' => $block['media_id'],
                ];

                if ($block['id'] !== null) {
                    $model = $existing->get($block['id']);
                    $model->update($attributes);
                } else {
                    $model = $locked->blocks()->create($attributes);
                }

                $idByRef[$block['ref']] = $model->id;
                $kept[$model->id] = true;
            }

            // Removed blocks are deleted last, after every kept child has
            // been re-parented, so a parent's cascade can't take them along.
            $removed = $existing->keys()->reject(fn ($id) => isset($kept[$id]))->values();
            if ($removed->isNotEmpty()) {
                PageBlock::query()->whereIn('id', $removed)->delete();
            }

            $attributes = [
                'title' => $definition['title'],
                'slug' => $definition['slug'],
                'settings' => $definition['settings'],
                'style_settings' => $definition['style_settings'],
                'lock_version' => $locked->lock_version + 1,
                'updated_by' => $actor->getAuthIdentifier(),
            ];
            if (array_key_exists('custom_css', $definition)) {
                $attributes['custom_css'] = $definition['custom_css'];
            }

            $wasPublished = $locked->status === PageStatus::Published;
            if ($publish) {
                $attributes['status'] = PageStatus::Published;
                $attributes['published_at'] = $locked->published_at ?? now();
            }

            $locked->update($attributes);

            if (! $autosave) {
                $this->audit->record(AuditEvent::PageUpdated, $locked, $actor, "Saved page \"{$locked->title}\".", [
                    'block_count' => count($definition['blocks']),
                    'removed_blocks' => $removed->count(),
                ]);
            }
            if ($publish && ! $wasPublished) {
                $this->audit->record(AuditEvent::PagePublished, $locked, $actor, "Published page \"{$locked->title}\".");
            }

            $this->lastIdsByRef = $idByRef;

            return $locked->fresh('blocks');
        });
    }

    public function publish(Page $page, Authenticatable $actor): Page
    {
        if (! $page->blocks()->exists()) {
            throw ValidationException::withMessages(['status' => 'Add at least one block before publishing.']);
        }

        return $this->transition($page, $actor, PageStatus::Published, AuditEvent::PagePublished, 'Published');
    }

    public function deactivate(Page $page, Authenticatable $actor): Page
    {
        return $this->transition($page, $actor, PageStatus::Deactivated, AuditEvent::PageDeactivated, 'Deactivated');
    }

    public function archive(Page $page, Authenticatable $actor): Page
    {
        return $this->transition($page, $actor, PageStatus::Archived, AuditEvent::PageArchived, 'Archived');
    }

    /** Archived -> Deactivated (never straight back to live). */
    public function restore(Page $page, Authenticatable $actor): Page
    {
        return $this->transition($page, $actor, PageStatus::Deactivated, AuditEvent::PageRestored, 'Restored');
    }

    private function transition(Page $page, Authenticatable $actor, PageStatus $to, AuditEvent $event, string $verb): Page
    {
        return DB::transaction(function () use ($page, $actor, $to, $event, $verb) {
            $locked = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($locked->status === $to) {
                return $locked;
            }

            $from = $locked->status;
            $locked->update([
                'status' => $to,
                'published_at' => $to === PageStatus::Published ? ($locked->published_at ?? now()) : $locked->published_at,
                'updated_by' => $actor->getAuthIdentifier(),
            ]);

            $this->audit->record($event, $locked, $actor, "{$verb} page \"{$locked->title}\".", ['from' => $from->value, 'to' => $to->value]);

            return $locked;
        });
    }

    /**
     * Copies settings, design, custom CSS and the block tree (form/media
     * blocks keep REFERENCING the same form/image -- nothing is copied,
     * and no form submissions are involved). The copy starts as a draft.
     */
    public function duplicate(Page $source, Authenticatable $actor): Page
    {
        return DB::transaction(function () use ($source, $actor) {
            $copy = Page::create([
                'title' => Str::limit('Copy of '.$source->title, 200, ''),
                'slug' => $this->uniqueSlug($source->slug.'-copy'),
                'status' => PageStatus::Draft,
                'settings' => $source->settings,
                'style_settings' => $source->style_settings,
                'custom_css' => $source->custom_css,
                'lock_version' => 1,
                'created_by' => $actor->getAuthIdentifier(),
                'updated_by' => $actor->getAuthIdentifier(),
            ]);

            $newIds = [];
            // Parents first (parent_id null sorts first), then children.
            $blocks = $source->blocks()->get()->sortBy(fn (PageBlock $b) => [$b->parent_id === null ? 0 : 1, $b->sort_order, $b->id]);
            foreach ($blocks as $block) {
                $newIds[$block->id] = $copy->blocks()->create([
                    'parent_id' => $block->parent_id !== null ? ($newIds[$block->parent_id] ?? null) : null,
                    'slot' => $block->slot,
                    'type' => $block->type,
                    'sort_order' => $block->sort_order,
                    'settings' => $block->settings,
                    'form_id' => $block->form_id,
                    'media_id' => $block->media_id,
                ])->id;
            }

            $this->audit->record(AuditEvent::PageDuplicated, $copy, $actor, "Duplicated \"{$source->title}\" as \"{$copy->title}\".", ['source_page_id' => $source->id]);

            return $copy;
        });
    }

    public function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = rtrim(Str::limit(Str::slug($base), 90, ''), '-') ?: 'page';
        $candidate = $slug;
        $suffix = 2;

        while (Page::query()->where('slug', $candidate)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }
}
