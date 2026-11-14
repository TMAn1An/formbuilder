<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Services\Forms\FieldSettingsSchema;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\BuildsPages;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Pictures shown ON a form (not visitor uploads): the banner above the
 * title (forms.settings banner_*) and the Image layout block
 * (type image_block). Both use the shared media library (page_media) and
 * the same upload validation as page images.
 */
class FormImagesTest extends TestCase
{
    use BuildsForms, BuildsPages;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function uploadToForm(User $user, Form $form, ?UploadedFile $file = null): PageMedia
    {
        $this->actingAs($user)->post("/admin/forms/{$form->id}/media", ['image' => $file ?? UploadedFile::fake()->image('banner.jpg', 1200, 400)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonStructure(['media' => ['id', 'url', 'name', 'width', 'height']]);

        return PageMedia::query()->latest('id')->firstOrFail();
    }

    public function test_form_media_upload_is_validated_stored_publicly_and_listed_in_the_builder(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $media = $this->uploadToForm($user, $form);

        Storage::disk('public')->assertExists($media->path);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame($user->id, $media->uploaded_by);
        $this->get($media->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        foreach ([
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'php disguised as jpg' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),
            'too large' => UploadedFile::fake()->image('huge.jpg')->size(FieldSettingsSchema::mediaUploadMaxKb() + 1),
        ] as $case => $bad) {
            $this->actingAs($user)->postJson("/admin/forms/{$form->id}/media", ['image' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['image']);
        }
        $this->assertSame(1, PageMedia::count());

        // The builder boots with the library, so the upload is selectable.
        $html = $this->actingAs($user)->get("/admin/forms/{$form->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('"media":[{"id":'.$media->id, $html);
        $this->assertStringContainsString('"mediaMaxKb":'.FieldSettingsSchema::mediaUploadMaxKb(), $html);
    }

    public function test_only_users_who_may_edit_the_form_can_upload_to_it(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $form = $this->createFormVia($owner);
        $image = fn () => ['image' => UploadedFile::fake()->image('a.jpg', 10, 10)];

        auth()->logout();
        $this->postJson("/admin/forms/{$form->id}/media", $image())->assertUnauthorized();

        $outsider = User::factory()->create();
        Gate::define('formbuilder.access', fn (User $u) => $u->is_admin);
        $this->actingAs($outsider)->postJson("/admin/forms/{$form->id}/media", $image())->assertForbidden();

        $form->update(['status' => FormStatus::Archived]);
        $this->actingAs($owner)->postJson("/admin/forms/{$form->id}/media", $image())->assertForbidden();

        $this->assertSame(0, PageMedia::count());
    }

    public function test_banner_settings_are_validated_saved_and_rendered_as_a_section_above_the_form(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Volunteer Signup');
        $media = $this->uploadToForm($user, $form);
        $fields = [$this->fieldDef('text', 'name', 'Name')];

        $this->saveDefinition($user, $form, $fields, ['settings' => [
            'banner_media_id' => 999999, 'banner_height' => 5000, 'banner_alt' => str_repeat('x', 301), 'banner_position' => 'left', 'banner_width' => 'huge',
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'settings.banner_media_id', 'settings.banner_height', 'settings.banner_alt', 'settings.banner_position', 'settings.banner_width',
        ]);

        $this->saveDefinition($user, $form, $fields, ['intent' => 'publish', 'settings' => [
            'banner_media_id' => (string) $media->id, 'banner_alt' => '  BECITHCON 2026 banner ', 'banner_height' => 220,
        ]])->assertOk()->assertJsonPath('state.form.settings.banner_media_id', $media->id)->assertJsonPath('state.form.settings.banner_alt', 'BECITHCON 2026 banner')
            ->assertJsonPath('state.form.settings.banner_position', 'top')->assertJsonPath('state.form.settings.banner_width', 'form');
        $form = $form->fresh();

        // Its own section, before (outside) the form card; it shares the form's width/alignment variables.
        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<figure class="ff-banner-section ff-banner-section--top ff-banner-section--form" id="ff-form-'.$form->id.'-banner">\s*<img src="'.preg_quote($media->url(), '#').'" alt="BECITHCON 2026 banner"\s+width="1200"\s+height="400"\s+style="max-height:220px;object-fit:cover"\s*>\s*</figure>\s*<div class="ff-form" id="ff-form-'.$form->id.'">\s*<h1 class="ff-title">Volunteer Signup</h1>#',
            $html,
        );
        $this->assertStringContainsString('<style>#ff-form-'.$form->id.',#ff-form-'.$form->id.'-banner{', $html);
    }

    public function test_banner_can_go_below_the_form_and_span_the_full_width(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Membership Drive');
        $media = $this->uploadToForm($user, $form);

        $this->saveDefinition($user, $form, [$this->fieldDef('text', 'name', 'Name')], ['intent' => 'publish', 'settings' => [
            'banner_media_id' => $media->id, 'banner_position' => 'bottom', 'banner_width' => 'full',
        ]])->assertOk();
        $form = $form->fresh();

        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#</form>.*?</div>\s*<figure class="ff-banner-section ff-banner-section--bottom ff-banner-section--full" id="ff-form-'.$form->id.'-banner">#s',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'ff-banner-section--'.'bottom'), 'Only one banner, below the form.');
        $this->assertLessThan(strpos($html, 'ff-banner-section--bottom'), strpos($html, 'class="ff-title"'));
    }

    public function test_a_form_without_a_banner_renders_exactly_as_before(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')]);

        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('ff-banner', $html);
        $this->assertStringNotContainsString('ff-image', $html);
        $this->assertSame([
            'banner_media_id' => null, 'banner_alt' => null, 'banner_height' => null, 'banner_position' => 'top', 'banner_width' => 'form',
        ], array_intersect_key($form->settings, array_flip(['banner_media_id', 'banner_alt', 'banner_height', 'banner_position', 'banner_width'])));
    }

    public function test_image_block_settings_are_validated_and_normalized(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);
        $media = $this->uploadToForm($user, $form);

        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'img2', 'Image', ['settings' => [
                'media_id' => 999999, 'max_width' => 99999, 'align' => 'justify', 'radius' => -1, 'link_url' => 'javascript:alert(1)',
            ]]),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'fields.0.settings.media_id', 'fields.0.settings.max_width',
            'fields.0.settings.align', 'fields.0.settings.radius', 'fields.0.settings.link_url',
        ]);

        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'img', 'Image', ['settings' => [
                'media_id' => (string) $media->id, 'alt' => ' Team photo ', 'max_width' => '480', 'align' => 'center',
                'radius' => '12', 'link_url' => 'https://ieee.org', 'new_tab' => true, 'placeholder' => 'not an image-block key',
            ]]),
        ])->assertOk();

        $field = $form->fresh()->fields()->sole();
        $this->assertEquals([
            'media_id' => $media->id, 'alt' => 'Team photo', 'max_width' => 480, 'align' => 'center',
            'radius' => 12, 'link_url' => 'https://ieee.org', 'new_tab' => true,
        ], $field->settings);
        $this->assertFalse($field->required);
    }

    public function test_image_block_renders_on_the_public_form_and_collects_nothing(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Event Feedback');
        $media = $this->uploadToForm($user, $form, UploadedFile::fake()->image('team.png', 640, 480));
        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'team_photo', 'Image', ['settings' => [
                'media_id' => $media->id, 'alt' => 'Our <team>', 'max_width' => 320, 'align' => 'right', 'radius' => 8,
                'link_url' => 'https://ieee.org', 'new_tab' => true,
            ]]),
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
        ], ['intent' => 'publish'])->assertOk();
        auth()->logout();
        $form = $form->fresh();

        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertStringContainsString('<figure class="ff-image" style="text-align:right">', $html);
        $this->assertStringContainsString('<a href="https://ieee.org"  target="_blank" rel="noopener noreferrer" >', $html);
        $this->assertStringContainsString('<img src="'.$media->url().'" alt="Our &lt;team&gt;" loading="lazy"', $html);
        $this->assertStringContainsString('style="max-width:min(100%,320px);border-radius:8px"', $html);
        // Not an input: nothing named after the block is posted or stored.
        $this->assertStringNotContainsString('name="team_photo"', $html);

        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha', 'team_photo' => 'injected'])->assertSessionHasErrorsIn('form_'.$form->id);
        $this->assertSame(0, FormSubmission::count(), 'A posted value for the Image block is refused, never stored.');
        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha'])->assertSessionHasNoErrors();
        $submission = FormSubmission::sole();
        $this->assertSame(['name'], $submission->values()->pluck('field_key')->all());

        $this->actingAs($user)->get("/admin/forms/{$form->id}/submissions/export.xlsx")->assertOk();
    }

    public function test_image_block_can_be_hidden_by_a_condition(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);
        $media = $this->uploadToForm($user, $form);
        $this->saveDefinition($user, $form, [
            $this->fieldDef('radio', 'attending', 'Attending?', ['settings' => ['options' => [['label' => 'Yes', 'value' => 'yes'], ['label' => 'No', 'value' => 'no']]]]),
            $this->fieldDef('image_block', 'venue_map', 'Image', [
                'settings' => ['media_id' => $media->id, 'alt' => 'Venue map'],
                'conditional_rules' => ['action' => 'show', 'match' => 'all', 'conditions' => [['field' => 'attending', 'operator' => 'equals', 'value' => 'yes']]],
            ]),
        ], ['intent' => 'publish'])->assertOk();

        $html = $this->get('/forms/'.$form->fresh()->slug)->assertOk()->getContent();
        $this->assertStringContainsString('data-ff-key="venue_map"', $html);
        $this->assertStringContainsString('"key":"venue_map","type":"image_block","collectsValue":false,"rules":{"action":"show"', $html);
    }

    public function test_image_block_whose_image_is_missing_is_skipped_not_broken(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);
        $media = $this->uploadToForm($user, $form);
        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'img', 'Image', ['settings' => ['media_id' => $media->id]]),
            $this->fieldDef('text', 'name', 'Name'),
        ], ['intent' => 'publish', 'settings' => ['banner_media_id' => $media->id]])->assertOk();

        $media->delete(); // e.g. removed directly in the database

        $html = $this->get('/forms/'.$form->fresh()->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('ff-image', $html);
        $this->assertStringNotContainsString('ff-banner', $html);
        $this->assertStringContainsString('name="name"', $html);
    }

    public function test_duplicating_a_form_keeps_its_pictures_and_shares_the_media(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Original');
        $media = $this->uploadToForm($user, $form);
        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'img', 'Image', ['settings' => ['media_id' => $media->id]]),
        ], ['settings' => ['banner_media_id' => $media->id]])->assertOk();

        $this->actingAs($user)->post("/admin/forms/{$form->id}/duplicate")->assertRedirect();
        $copy = Form::query()->latest('id')->firstOrFail();

        $this->assertNotSame($form->id, $copy->id);
        $this->assertSame($media->id, $copy->settings['banner_media_id']);
        $this->assertSame($media->id, $copy->fields()->sole()->settings['media_id']);
        $this->assertSame(1, PageMedia::count());
    }

    public function test_a_just_added_image_block_without_a_picture_saves_and_is_not_shown(): void
    {
        // Reported: adding an Image block made every save (and autosave) fail
        // with "The fields.0.settings.media id field is required." until a
        // picture was chosen.
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'image', 'Image'),
            $this->fieldDef('email', 'email', 'Email'),
        ], ['intent' => 'publish'])->assertOk();

        $this->assertSame([], $form->fresh()->fields()->where('key', 'image')->sole()->settings);
        $html = $this->get('/forms/'.$form->fresh()->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ff-key="image"', $html);
        $this->assertStringContainsString('name="email"', $html);
    }

    public function test_an_unused_image_can_be_deleted_from_the_library(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);
        $media = $this->uploadToForm($user, $form);
        Storage::disk('public')->assertExists($media->path);

        $this->actingAs($user)->deleteJson('/admin/media/'.$media->uuid)->assertOk()->assertJsonPath('deleted', $media->id);

        $this->assertSame(0, PageMedia::count());
        Storage::disk('public')->assertMissing($media->path);
        $this->get($media->url())->assertNotFound();
        $this->actingAs($user)->deleteJson('/admin/media/'.$media->uuid)->assertNotFound();
    }

    public function test_an_image_in_use_anywhere_is_never_deleted(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Recruitment 2026');
        $banner = $this->uploadToForm($user, $form);
        $picture = $this->uploadToForm($user, $form);
        $archivedPicture = $this->uploadToForm($user, $form);
        $pageImage = $this->uploadToForm($user, $form);

        $this->saveDefinition($user, $form, [
            $this->fieldDef('image_block', 'img', 'Image', ['settings' => ['media_id' => $picture->id]]),
            $this->fieldDef('image_block', 'old_img', 'Image', ['is_active' => false, 'settings' => ['media_id' => $archivedPicture->id]]),
        ], ['settings' => ['banner_media_id' => $banner->id]])->assertOk();
        $page = $this->createPageVia($user, 'Join us');
        $this->savePage($user, $page, [$this->block('i', 'image', ['media_id' => $pageImage->id])])->assertOk();

        foreach ([
            [$banner, 'Form "Recruitment 2026" (banner)'],
            [$picture, 'Form "Recruitment 2026" (Image block)'],
            [$archivedPicture, 'Form "Recruitment 2026" (Image block)'],
            [$pageImage, 'Page "Join us" (Image block)'],
        ] as [$media, $where]) {
            $this->actingAs($user)->deleteJson('/admin/media/'.$media->uuid)
                ->assertStatus(409)->assertJsonPath('usages', [$where]);
            Storage::disk('public')->assertExists($media->path);
        }
        $this->assertSame(4, PageMedia::count());
    }

    public function test_only_admins_with_access_can_delete_images(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $form = $this->createFormVia($user);
        $media = $this->uploadToForm($user, $form);

        auth()->logout();
        $this->deleteJson('/admin/media/'.$media->uuid)->assertUnauthorized();

        Gate::define('formbuilder.access', fn (User $u) => $u->is_admin);
        $this->actingAs(User::factory()->create())->deleteJson('/admin/media/'.$media->uuid)->assertForbidden();
        $this->actingAs($user)->deleteJson('/admin/media/'.$media->id)->assertNotFound(); // numeric ids aren't addressable

        $this->assertSame(1, PageMedia::count());
    }
}
