<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use TMAn1An\FormBuilder\Enums\AuditEvent;
use TMAn1An\FormBuilder\Enums\PageStatus;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\BuildsPages;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Page Builder end to end: creating pages, every block type rendering,
 * page images, embedded forms (same renderer, submissions under the
 * original form), publishing rules, duplication and security.
 */
class PageBuilderTest extends TestCase
{
    use BuildsForms, BuildsPages;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function uploadMedia(User $user, ?UploadedFile $file = null): PageMedia
    {
        $this->actingAs($user)->post('/admin/pages/media', ['image' => $file ?? UploadedFile::fake()->image('banner.jpg', 800, 400)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('media.width', 800);

        return PageMedia::query()->latest('id')->firstOrFail();
    }

    public function test_page_is_created_as_a_draft_and_the_builder_loads(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user, 'IEEE Volunteer Registration');

        $this->assertSame('ieee-volunteer-registration', $page->slug);
        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertContains(AuditEvent::PageCreated, $this->audit()->eventsFor($page));

        $this->get("/admin/pages/{$page->id}/edit")->assertOk()
            ->assertSee('page-builder-data', false)
            ->assertSee('js/page-builder.js', false)
            ->assertSee('js/fb-kit.js', false);
        $this->get('/admin/pages')->assertOk()->assertSee('IEEE Volunteer Registration')->assertSee('data-fba-copy="http://localhost/pages/ieee-volunteer-registration"', false);
    }

    public function test_every_block_type_renders_on_the_published_page(): void
    {
        $user = User::factory()->create();
        $media = $this->uploadMedia($user);
        $page = $this->createPageVia($user, 'Volunteer Page');

        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Join <us>', 'level' => 'h1', 'align' => 'center', 'font_size' => 44, 'font_weight' => '800', 'color' => '#002855', 'margin_bottom' => 12]),
            $this->block('img', 'image', ['media_id' => $media->id, 'alt' => 'Volunteers at work', 'width' => '600px', 'max_width' => '100%', 'align' => 'right', 'radius' => 16, 'link_url' => 'https://ieee.org', 'new_tab' => true]),
            $this->block('p', 'paragraph', ['content' => '<p>We need <strong>you</strong>.</p><script>alert(1)</script>', 'align' => 'center']),
            $this->block('c', 'columns', ['count' => 2, 'gap' => 30]),
            $this->block('c1', 'heading', ['text' => 'Left column'], parent: 'c', column: 0),
            $this->block('c2', 'heading', ['text' => 'Right column'], parent: 'c', column: 1),
            $this->block('s', 'section', ['background_color' => '#eef5fb', 'padding_y' => 50]),
            $this->block('s1', 'spacer', ['height' => 40], parent: 's'),
            $this->block('d', 'divider', ['thickness' => 3, 'color' => '#00629b', 'width' => '50%']),
            $this->block('btn', 'button', ['text' => 'Read more', 'url' => 'https://ieee.org/volunteer']),
            $this->block('x', 'html', ['content' => '<h3>Notice</h3><iframe src="https://evil.test"></iframe>']),
        ], ['intent' => 'publish'])->assertOk();

        auth()->logout();
        $html = $this->get('/pages/volunteer-page')->assertOk()
            ->assertSee('<h1 class="pb-block pb-heading pb-heading--h1"', false)
            ->assertSee('style="text-align:center;font-size:44px;font-weight:800;color:#002855;margin-bottom:12px" >Join &lt;us&gt;</h1>', false)
            ->assertSee('src="'.$media->url().'"', false)
            ->assertSee('alt="Volunteers at work"', false)
            ->assertSee('style="width:600px;max-width:100%;border-radius:16px"', false)
            ->assertSee('style="justify-content:flex-end"', false)
            ->assertSee('<a href="https://ieee.org" target="_blank" rel="noopener noreferrer">', false)
            ->assertSee('<p>We need <strong>you</strong>.</p>', false)
            ->assertDontSee('alert(1)', false)
            ->assertSee('--pb-cols:2;--pb-col-gap:30px', false)
            ->assertSee('Left column')->assertSee('Right column')
            ->assertSee('background-color:#eef5fb;padding:50px 24px', false)
            ->assertSee('height:40px', false)
            ->assertSee('border-top:3px solid #00629b;width:50%', false)
            ->assertSee('href="https://ieee.org/volunteer"', false)
            ->assertSee('<h3>Notice</h3>', false)
            ->assertDontSee('<iframe', false)
            ->assertSee('<title>Volunteer Page</title>', false)
            ->getContent();

        // Blocks appear in the saved order.
        $this->assertTrue(strpos($html, 'Join &lt;us&gt;') < strpos($html, 'Left column'));
        $this->assertTrue(strpos($html, 'Left column') < strpos($html, 'Read more'));
    }

    public function test_page_media_upload_validates_and_is_served_publicly_by_uuid(): void
    {
        $user = User::factory()->create();
        $media = $this->uploadMedia($user);

        $this->assertMatchesRegularExpression('#^form-builder/pages/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $media->path);
        Storage::disk('public')->assertExists($media->path);
        Storage::disk('local')->assertMissing($media->path);

        auth()->logout();
        $this->get($media->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/formbuilder-assets/media/'.$media->id)->assertNotFound(); // numeric ids are not addressable

        foreach ([
            UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1; ?>'),
            UploadedFile::fake()->image('huge.jpg', 10, 10)->size(20000),
        ] as $bad) {
            $this->actingAs($user)->postJson('/admin/pages/media', ['image' => $bad])->assertStatus(422)->assertJsonValidationErrors(['image']);
        }
        auth()->logout();
        $this->postJson('/admin/pages/media', ['image' => UploadedFile::fake()->image('a.jpg')])->assertUnauthorized();
    }

    public function test_embedded_form_renders_with_the_form_renderer_and_submits_under_the_original_form(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [
            $this->fieldDef('text', 'full_name', 'Full name', ['required' => true]),
            $this->fieldDef('email', 'email', 'Email', ['required' => true]),
        ], ['name' => 'Volunteer Registration Form']);

        $page = $this->createPageVia($user, 'Volunteer Landing');
        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Sign up below']),
            $this->block('f', 'form', ['form_id' => $form->id, 'hide_description' => true]),
        ], ['intent' => 'publish'])->assertOk();
        auth()->logout();

        $this->get('/pages/volunteer-landing')->assertOk()
            ->assertSee('id="ff-form-'.$form->id.'"', false)
            ->assertSee('<h2 class="ff-title">Volunteer Registration Form</h2>', false)
            ->assertSee('action="http://localhost/forms/'.$form->slug.'"', false)
            ->assertSee('name="_embed_page" value="'.$page->id.'"', false)
            ->assertSee('name="full_name"', false);

        // Validation errors return to the page, in this form's own error bag.
        $this->from('/pages/volunteer-landing')
            ->post("/forms/{$form->slug}", ['_embed_page' => $page->id, 'full_name' => '', 'email' => 'nope'])
            ->assertRedirect('/pages/volunteer-landing#ff-form-'.$form->id)
            ->assertSessionHasErrorsIn('form_'.$form->id, ['full_name', 'email']);

        $this->post("/forms/{$form->slug}", ['_embed_page' => $page->id, 'full_name' => 'Ayesha', 'email' => 'a@example.com'])
            ->assertRedirect('/pages/volunteer-landing#ff-form-'.$form->id);

        $submission = FormSubmission::sole();
        $this->assertSame($form->id, $submission->form_id, 'Stored under the original form.');
        $this->assertSame($page->id, $submission->metadata['embedded_in_page']);

        $this->get('/pages/volunteer-landing')->assertSee('Thank you! Your response has been recorded.');
        $this->assertSame(1, $form->submissions()->count());
    }

    public function test_embed_page_id_cannot_redirect_to_an_unrelated_page(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')]);
        $other = $this->createPageVia($user, 'Unrelated');
        $this->savePage($user, $other, [$this->block('h', 'heading', ['text' => 'x'])], ['intent' => 'publish'])->assertOk();
        auth()->logout();

        $this->post("/forms/{$form->slug}", ['_embed_page' => $other->id, 'name' => 'x'])
            ->assertRedirect("http://localhost/forms/{$form->slug}");
        $this->post("/forms/{$form->slug}", ['_embed_page' => 'javascript:alert(1)', 'name' => 'x'])
            ->assertRedirect("http://localhost/forms/{$form->slug}");
    }

    public function test_unpublished_embedded_form_is_hidden_publicly_but_flagged_in_preview(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Draft Form');
        $this->saveDefinition($user, $form, [$this->fieldDef('text', 'name', 'Name')])->assertOk();
        $page = $this->createPageVia($user);
        $this->savePage($user, $page, [
            $this->block('h', 'heading', ['text' => 'Hello']),
            $this->block('f', 'form', ['form_id' => $form->id]),
        ], ['intent' => 'publish'])->assertOk();

        $this->get("/admin/pages/{$page->id}/preview")->assertOk()->assertSee('is Draft and won&#039;t appear on the public page', false);
        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertOk()->assertSee('Hello')->assertDontSee('name="name"', false);
    }

    public function test_draft_deactivated_and_archived_pages_are_not_public_but_preview_works_for_admins(): void
    {
        $user = User::factory()->admin()->create();
        $page = $this->createPageVia($user);
        $this->savePage($user, $page, [$this->block('h', 'heading', ['text' => 'Secret draft'])])->assertOk();

        $this->get("/admin/pages/{$page->id}/preview")->assertOk()->assertSee('Secret draft')->assertSee('Preview');
        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertNotFound();
        $this->get("/admin/pages/{$page->id}/preview")->assertRedirect('/login');

        $this->actingAs($user)->post("/admin/pages/{$page->id}/publish")->assertRedirect();
        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertOk()->assertSee('Secret draft');

        $this->actingAs($user)->post("/admin/pages/{$page->id}/deactivate")->assertRedirect();
        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertNotFound();

        $this->actingAs($user)->post("/admin/pages/{$page->id}/archive")->assertRedirect();
        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertNotFound();

        $events = $this->audit()->eventsFor($page);
        foreach ([AuditEvent::PagePublished, AuditEvent::PageDeactivated, AuditEvent::PageArchived] as $event) {
            $this->assertContains($event, $events);
        }
    }

    public function test_archiving_pages_requires_archive_permission(): void
    {
        $editor = User::factory()->create();
        $page = $this->createPageVia($editor);

        $this->actingAs($editor)->post("/admin/pages/{$page->id}/archive")->assertForbidden();
        $this->assertSame(PageStatus::Draft, $page->fresh()->status);
    }

    public function test_duplicate_copies_blocks_and_references_but_no_submissions(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')]);
        $media = $this->uploadMedia($user);
        $page = $this->createPageVia($user);
        $this->savePage($user, $page, [
            $this->block('c', 'columns', ['count' => 2]),
            $this->block('i', 'image', ['media_id' => $media->id], parent: 'c', column: 1),
            $this->block('f', 'form', ['form_id' => $form->id]),
        ], ['intent' => 'publish'])->assertOk();
        $this->post("/forms/{$form->slug}", ['_embed_page' => $page->id, 'name' => 'A']);
        $this->assertSame(1, FormSubmission::count());

        $this->actingAs($user)->post("/admin/pages/{$page->id}/duplicate")->assertRedirect();

        $copy = Page::query()->whereKeyNot($page->id)->sole();
        $this->assertSame(PageStatus::Draft, $copy->status);
        $this->assertSame(3, $copy->blocks()->count());
        $columns = $copy->blocks()->where('type', 'columns')->sole();
        $image = $copy->blocks()->where('type', 'image')->sole();
        $this->assertSame($columns->id, $image->parent_id, 'Children re-parented to the copied container.');
        $this->assertSame(1, $image->slot);
        $this->assertSame($media->id, $image->media_id);
        $this->assertSame($form->id, $copy->blocks()->where('type', 'form')->sole()->form_id, 'Still references the same form.');
        $this->assertSame(1, FormSubmission::count(), 'No submissions duplicated.');
        $this->assertSame(6, PageBlock::count());
    }

    public function test_page_custom_css_is_scoped_and_title_is_escaped(): void
    {
        $admin = User::factory()->admin()->create();
        $page = $this->createPageVia($admin, '</title><script>alert(1)</script>');
        $this->savePage($admin, $page, [$this->block('h', 'heading', ['text' => 'x'])], [
            'intent' => 'publish',
            'custom_css' => "body { background: red } .pb-heading { color: blue }\n@import url(https://evil.test/x.css);",
        ])->assertOk();

        auth()->logout();
        $this->get('/pages/'.$page->fresh()->slug)->assertOk()
            ->assertSee("#pb-page-{$page->id}{background: red;}", false)
            ->assertSee("#pb-page-{$page->id} .pb-heading{color: blue;}", false)
            ->assertDontSee('@import', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_html_tampered_in_the_database_is_still_sanitized_on_render(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);
        $this->savePage($user, $page, [$this->block('p', 'paragraph', ['content' => '<p>ok</p>'])], ['intent' => 'publish'])->assertOk();
        PageBlock::query()->update(['settings' => json_encode(['content' => '<p>ok</p><script>alert("db")</script><img src=x onerror=alert(2)>', 'color' => 'red;}body{display:none'])]);

        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertOk()
            ->assertSee('<p>ok</p>', false)
            ->assertDontSee('alert("db")', false)
            ->assertDontSee('onerror', false)
            ->assertDontSee('display:none', false);
    }

    public function test_form_preview_endpoint_renders_the_real_renderer_without_submission(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')]);

        $this->actingAs($user)->get("/admin/pages/form-preview/{$form->id}")->assertOk()
            ->assertSee('id="ff-form-'.$form->id.'"', false)
            ->assertSee('action="#"', false)
            ->assertSee('disabled title="Preview only', false);
        auth()->logout();
        $this->get("/admin/pages/form-preview/{$form->id}")->assertRedirect('/login');
    }
}
