<?php

namespace TMAn1An\FormBuilder\Tests;

use Illuminate\Testing\TestResponse;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Styling\PageStyleSchema;
use TMAn1An\FormBuilder\Tests\Fixtures\User;

/** Builds pages the way the real builder does: POST to create, JSON PUT of the flat block list to save. */
trait BuildsPages
{
    protected function block(string $ref, string $type, array $settings = [], ?string $parent = null, int $column = 0, ?int $id = null, ?int $order = null): array
    {
        return array_filter([
            'ref' => $ref,
            'id' => $id,
            'parent' => $parent,
            'column' => $parent !== null ? $column : null,
            'order' => $order,
            'type' => $type,
            'settings' => $settings,
        ], fn ($v) => $v !== null);
    }

    protected function createPageVia(User $user, string $title = 'Volunteer Page'): Page
    {
        $this->actingAs($user)->post('/admin/pages', ['title' => $title])->assertRedirect();

        return Page::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    protected function savePage(User $user, Page $page, array $blocks, array $overrides = []): TestResponse
    {
        $fresh = $page->fresh();

        return $this->actingAs($user)->putJson("/admin/pages/{$page->id}/builder", array_replace([
            'version' => $fresh->lock_version,
            'intent' => 'save',
            'title' => $fresh->title,
            'slug' => $fresh->slug,
            'settings' => [],
            'style_settings' => PageStyleSchema::defaults(),
            'blocks' => $blocks,
        ], $overrides));
    }

    /** The stored blocks in the exact shape the builder sends back. */
    protected function blocksAsPayload(Page $page): array
    {
        return $page->fresh()->blocks->map(fn (PageBlock $b) => array_filter([
            'ref' => 'b'.$b->id,
            'id' => $b->id,
            'parent' => $b->parent_id ? 'b'.$b->parent_id : null,
            'column' => $b->parent_id ? $b->slot : null,
            'type' => $b->type->value,
            'settings' => array_filter(($b->settings ?? []) + ['form_id' => $b->form_id, 'media_id' => $b->media_id], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null))->values()->all();
    }
}
