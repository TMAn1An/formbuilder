<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use RuntimeException;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Services\Pages\PageBuilderService;
use TMAn1An\FormBuilder\Tests\BuildsPages;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Every structural invariant of a Page Builder save, checked BEFORE any
 * write (PageDefinitionValidator via SavePageDefinitionRequest), plus the
 * transactional guarantee of the write itself. See docs/PAGES.md §Save payload.
 */
class PageDefinitionValidationTest extends TestCase
{
    use BuildsPages;

    /** A section with a heading, and 2 columns holding a paragraph and a spacer. */
    private function savedTree(User $user, Page $page): array
    {
        $this->savePage($user, $page, [
            $this->block('s1', 'section', ['padding_y' => 40]),
            $this->block('h1', 'heading', ['text' => 'Welcome'], parent: 's1'),
            $this->block('c1', 'columns', ['count' => 2]),
            $this->block('p1', 'paragraph', ['content' => '<p>Left</p>'], parent: 'c1', column: 0),
            $this->block('sp1', 'spacer', ['height' => 20], parent: 'c1', column: 1),
        ])->assertOk();

        return $page->fresh()->blocks->keyBy(fn (PageBlock $b) => $b->type->value)->all();
    }

    public function test_a_valid_nested_definition_persists_with_order_parents_and_columns(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $blocks = $this->savedTree($user, $page);

        $this->assertNull($blocks['section']->parent_id);
        $this->assertSame($blocks['section']->id, $blocks['heading']->parent_id);
        $this->assertSame($blocks['columns']->id, $blocks['paragraph']->parent_id);
        $this->assertSame(0, $blocks['paragraph']->slot);
        $this->assertSame(1, $blocks['spacer']->slot);
        $this->assertSame(['section', 'heading', 'columns', 'paragraph', 'spacer'], $page->fresh()->blocks->map(fn ($b) => $b->type->value)->all());
        $this->assertSame(2, $page->fresh()->lock_version);
    }

    public function test_persisted_blocks_round_trip_with_their_ids_and_new_blocks_get_temporary_refs(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);

        $payload = $this->blocksAsPayload($page);
        $payload[] = $this->block('n99', 'divider', ['thickness' => 2]);
        $this->savePage($user, $page, $payload)->assertOk();

        $this->assertSame(6, $page->blocks()->count());
        $this->assertModelExists($blocks['heading']); // same rows, updated in place
    }

    public function test_duplicate_block_ids_are_rejected(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);

        $this->savePage($user, $page, [
            $this->block('a', 'heading', ['text' => 'A'], id: $blocks['heading']->id),
            $this->block('b', 'heading', ['text' => 'B'], id: $blocks['heading']->id),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.id']);

        $this->assertSame('Welcome', $blocks['heading']->fresh()->setting('text'));
    }

    public function test_the_same_block_cannot_be_both_a_root_and_a_child(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);
        $id = $blocks['heading']->id;

        $this->savePage($user, $page, [
            $this->block('s1', 'section', [], id: $blocks['section']->id),
            $this->block('root', 'heading', ['text' => 'Root copy'], id: $id),
            $this->block('child', 'heading', ['text' => 'Child copy'], id: $id, parent: 's1'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.2.id']);
    }

    public function test_duplicate_client_refs_are_rejected(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('x', 'heading', ['text' => 'A']),
            $this->block('x', 'spacer', []),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.ref']);
    }

    public function test_a_block_id_from_another_page_fails_explicitly(): void
    {
        $user = User::factory()->create();
        $mine = $this->createPageVia($user, 'Mine');
        $other = $this->createPageVia($user, 'Other');
        $foreign = $this->savedTree($user, $other)['heading'];

        $this->savePage($user, $mine, [
            $this->block('h', 'heading', ['text' => 'Hijacked'], id: $foreign->id),
        ])->assertStatus(422)->assertJsonFragment(['blocks.0.id' => ["Block #{$foreign->id} belongs to a different page."]]);

        $this->assertSame($other->id, $foreign->fresh()->page_id);
        $this->assertSame('Welcome', $foreign->fresh()->setting('text'));
        $this->assertSame(0, $mine->blocks()->count());
    }

    public function test_a_removed_block_id_cannot_be_restored_by_a_stale_payload(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);
        $staleId = $blocks['spacer']->id;

        // Remove the spacer.
        $payload = array_values(array_filter($this->blocksAsPayload($page), fn ($b) => $b['id'] !== $staleId));
        $this->savePage($user, $page, $payload)->assertOk();
        $this->assertModelMissing($blocks['spacer']);

        // A payload still naming it is rejected, not silently re-created.
        $payload[] = $this->block('old', 'spacer', ['height' => 20], id: $staleId);
        $this->savePage($user, $page, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks.'.(count($payload) - 1).'.id']);
        $this->assertDatabaseMissing('page_blocks', ['id' => $staleId]);
    }

    public function test_orphan_children_are_rejected(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Lost'], parent: 'does-not-exist'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.0.parent']);
    }

    public function test_a_block_cannot_be_its_own_parent(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('s', 'section', [], parent: 's'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.0.parent']);
    }

    public function test_containers_cannot_be_nested(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('c', 'columns', ['count' => 2]),
            $this->block('s', 'section', [], parent: 'c', column: 0),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.type']);
    }

    public function test_a_child_cannot_itself_have_children(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('s', 'section', []),
            $this->block('h', 'heading', ['text' => 'Child'], parent: 's'),
            $this->block('g', 'spacer', [], parent: 'h'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.2.parent']);
    }

    public function test_a_container_inside_a_container_is_rejected_even_two_levels_down(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('s', 'section', []),
            $this->block('c', 'columns', ['count' => 2], parent: 's'),
            $this->block('h', 'heading', ['text' => 'x'], parent: 'c'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.type', 'blocks.2.parent']);
    }

    public function test_non_container_blocks_cannot_contain_children(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Not a container']),
            $this->block('p', 'paragraph', ['content' => '<p>x</p>'], parent: 'h'),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.parent']);
    }

    public function test_nested_children_arrays_are_not_accepted(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('s', 'section', []) + ['children' => [[$this->block('h', 'heading', ['text' => 'x'])]]],
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.0.children']);
    }

    public function test_child_column_must_exist_in_its_parent(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('c', 'columns', ['count' => 2]),
            $this->block('h', 'heading', ['text' => 'Third column?'], parent: 'c', column: 2),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.column']);

        $this->savePage($user, $page, [
            $this->block('s', 'section', []),
            $this->block('h', 'heading', ['text' => 'Sections have one column'], parent: 's', column: 1),
        ])->assertStatus(422)->assertJsonValidationErrors(['blocks.1.column']);

        $this->savePage($user, $page, [
            $this->block('c', 'columns', ['count' => 4]),
            $this->block('h', 'heading', ['text' => 'ok'], parent: 'c', column: 3),
        ])->assertOk();
    }

    public function test_columns_layout_is_limited_to_two_three_or_four_columns(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        foreach ([1, 5, 'two'] as $count) {
            $this->savePage($user, $page, [$this->block('c', 'columns', ['count' => $count])])
                ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.settings.count']);
        }
    }

    public function test_block_type_must_be_supported(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [$this->block('x', 'carousel', [])])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.type']);
    }

    public function test_settings_are_validated_per_type_and_unknown_keys_dropped(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Hi', 'color' => 'red;}body{display:none', 'level' => 'h9', 'font_size' => 5000]),
            $this->block('b', 'button', ['text' => '', 'url' => 'javascript:alert(1)']),
            $this->block('i', 'divider', ['width' => '50vw']),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'blocks.0.settings.color', 'blocks.0.settings.level', 'blocks.0.settings.font_size',
            'blocks.1.settings.text', 'blocks.1.settings.url', 'blocks.2.settings.width',
        ]);

        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Hi', 'onclick' => 'steal()', 'content' => '<script>x</script>']),
        ])->assertOk();
        $this->assertSame(['text' => 'Hi'], PageBlock::firstOrFail()->settings);
    }

    public function test_form_block_must_reference_an_existing_embeddable_form(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $draft = Form::factory()->create(['created_by' => $user->id]);
        $archived = Form::factory()->create(['created_by' => $user->id, 'status' => FormStatus::Archived]);

        $this->savePage($user, $page, [$this->block('f', 'form', ['form_id' => 999999])])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.settings.form_id']);
        $this->savePage($user, $page, [$this->block('f', 'form', [])])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.settings.form_id']);
        $this->savePage($user, $page, [$this->block('f', 'form', ['form_id' => $archived->id])])
            ->assertStatus(422)->assertJsonFragment(['blocks.0.settings.form_id' => ['Archived forms cannot be embedded.']]);

        $this->savePage($user, $page, [$this->block('f', 'form', ['form_id' => $draft->id, 'hide_title' => true])])->assertOk();
        $block = PageBlock::firstOrFail();
        $this->assertSame($draft->id, $block->form_id, 'Stored as a reference (FK column), not a copy.');
        $this->assertSame(['hide_title' => true], $block->settings);
    }

    public function test_invalid_order_values_are_rejected_and_valid_ones_normalized(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [$this->block('a', 'spacer', [], order: -1)])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.order']);
        $this->savePage($user, $page, [$this->block('a', 'spacer', []) + ['order' => 'first']])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.order']);

        $this->savePage($user, $page, [
            $this->block('a', 'heading', ['text' => 'third'], order: 30),
            $this->block('b', 'heading', ['text' => 'first'], order: 10),
            $this->block('c', 'heading', ['text' => 'second'], order: 20),
        ])->assertOk();

        $this->assertSame(['first', 'second', 'third'], $page->fresh()->blocks->map(fn ($b) => $b->setting('text'))->all());
        $this->assertSame([1, 2, 3], $page->fresh()->blocks->pluck('sort_order')->all());
    }

    public function test_custom_html_is_sanitized(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('h', 'html', ['content' => '<h3>Notice</h3><script>alert(1)</script><p onclick="x()">Hi <a href="javascript:alert(2)">x</a></p><iframe src="https://evil.test"></iframe>']),
            $this->block('p', 'paragraph', ['content' => '<p>Safe <strong>bold</strong><img src=x onerror=alert(3)></p>']),
        ])->assertOk();

        $html = PageBlock::query()->where('type', PageBlockType::Html)->firstOrFail()->setting('content');
        $paragraph = PageBlock::query()->where('type', PageBlockType::Paragraph)->firstOrFail()->setting('content');
        foreach (['<script', 'onclick', 'javascript:', '<iframe'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }
        $this->assertStringContainsString('<h3>Notice</h3>', $html);
        $this->assertStringNotContainsString('onerror', $paragraph);
        $this->assertStringContainsString('<strong>bold</strong>', $paragraph);
    }

    public function test_custom_css_is_rejected_for_users_without_custom_code_access(): void
    {
        $editor = User::factory()->create();
        $page = $this->createPageVia($editor);

        $this->savePage($editor, $page, [], ['custom_css' => 'body { display: none }'])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_css']);
        $this->assertNull($page->fresh()->custom_css);

        $admin = User::factory()->admin()->create();
        $this->savePage($admin, $page, [], ['custom_css' => '.pb-heading { color: red }'])->assertOk();
        $this->assertSame('.pb-heading { color: red }', $page->fresh()->custom_css);

        // An editor saving without the key leaves the admin's CSS alone.
        $this->savePage($editor, $page, [$this->block('h', 'heading', ['text' => 'x'])])->assertOk();
        $this->assertSame('.pb-heading { color: red }', $page->fresh()->custom_css);
    }

    public function test_the_whole_definition_is_validated_before_anything_is_written(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);

        $payload = $this->blocksAsPayload($page);
        $payload[1]['settings']['text'] = 'Changed';                 // a valid edit...
        $payload[] = $this->block('bad', 'heading', ['text' => 'x'], parent: 'missing'); // ...plus an invalid block

        $this->savePage($user, $page, $payload)->assertStatus(422);

        $this->assertSame('Welcome', $blocks['heading']->fresh()->setting('text'));
        $this->assertSame(5, $page->blocks()->count());
        $this->assertSame(2, $page->fresh()->lock_version);
    }

    public function test_a_failure_mid_save_rolls_back_every_block_change(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $blocks = $this->savedTree($user, $page);

        // Valid payload: edits a block, removes the spacer, adds two blocks --
        // the second new block makes the database write fail half-way through.
        $payload = array_values(array_filter($this->blocksAsPayload($page), fn ($b) => $b['type'] !== 'spacer'));
        $payload[1]['settings']['text'] = 'Changed';
        $payload[] = $this->block('n1', 'heading', ['text' => 'New one']);
        $payload[] = $this->block('n2', 'heading', ['text' => 'boom']);

        PageBlock::creating(function (PageBlock $block) {
            if (($block->settings['text'] ?? null) === 'boom') {
                throw new RuntimeException('Simulated failure during save');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->savePage($user, $page, $payload);
            $this->fail('The save should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure during save', $e->getMessage());
        }

        $this->assertSame('Welcome', $blocks['heading']->fresh()->setting('text'));
        $this->assertModelExists($blocks['spacer']);
        $this->assertSame(5, $page->blocks()->count());
        $this->assertSame(0, PageBlock::query()->where('settings->text', 'New one')->count());
        $this->assertSame(2, $page->fresh()->lock_version);
    }

    public function test_stale_version_is_rejected(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $this->savedTree($user, $page);

        $this->savePage($user, $page, [], ['version' => 1])->assertStatus(409);
        $this->assertSame(5, $page->blocks()->count());
    }

    public function test_archived_pages_cannot_be_saved_and_guests_are_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $page = $this->createPageVia($admin);
        app(PageBuilderService::class)->archive($page, $admin);

        $this->savePage($admin, $page->fresh(), [$this->block('h', 'heading', ['text' => 'x'])])->assertForbidden();

        auth()->logout();
        $this->putJson("/admin/pages/{$page->id}/builder", [])->assertUnauthorized();
    }
}
