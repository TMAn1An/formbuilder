<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\FormSubmissionFile;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Image Upload field: real MIME/image validation (never the extension),
 * private storage under form-builder/submissions/{form}/{submission}/,
 * metadata-only database rows, admin-only delivery, and export references.
 */
class ImageUploadTest extends TestCase
{
    use BuildsForms;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function photoForm(array $settings = [], bool $required = true)
    {
        $user = User::factory()->create();

        return [$user, $this->publishedForm($user, [
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
            $this->fieldDef('image', 'photo', 'Photo', ['required' => $required, 'settings' => $settings]),
        ])];
    }

    public function test_image_field_settings_are_validated_and_persist(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $this->saveDefinition($user, $form, [
            $this->fieldDef('image', 'photo', 'Photo', ['settings' => ['max_size_kb' => 2048, 'allowed_types' => ['jpg', 'png'], 'help_text' => 'A clear headshot']]),
        ])->assertOk();
        $this->assertEquals(['max_size_kb' => 2048, 'allowed_types' => ['jpg', 'png'], 'help_text' => 'A clear headshot'], $form->fresh()->fields[0]->settings);

        $this->saveDefinition($user, $form->fresh(), [
            $this->fieldDef('image', 'photo', 'Photo', ['id' => $form->fresh()->fields[0]->id, 'settings' => ['allowed_types' => ['svg', 'gif'], 'max_size_kb' => 999999]]),
        ])->assertStatus(422)->assertJsonValidationErrors(['fields.0.settings.allowed_types.0', 'fields.0.settings.max_size_kb']);
    }

    public function test_valid_image_is_stored_privately_with_metadata_only(): void
    {
        [, $form] = $this->photoForm();

        $this->post("/forms/{$form->slug}", ['name' => 'Ayesha', 'photo' => UploadedFile::fake()->image('../../etc/My Photo!.jpg', 120, 80)])
            ->assertSessionHasNoErrors();

        $submission = FormSubmission::sole();
        $file = FormSubmissionFile::sole();
        $this->assertSame($submission->id, $file->form_submission_id, 'File belongs to the right submission.');
        $this->assertSame('local', $file->disk);
        $this->assertMatchesRegularExpression("#^form-builder/submissions/{$form->id}/{$submission->id}/[0-9a-f-]{36}\\.jpg$#", $file->path);
        $this->assertSame('image/jpeg', $file->mime_type);
        $this->assertSame([120, 80], [$file->width, $file->height]);
        $this->assertSame('My Photo.jpg', $file->original_name, 'Display name stripped of path parts and odd characters.');
        Storage::disk('local')->assertExists($file->path);

        $value = $submission->values()->where('field_key', 'photo')->sole();
        $this->assertSame(['file_id' => $file->id], $value->value, 'Only a reference is stored, never binary data.');
        $this->assertSame('My Photo.jpg', $value->display_value);
    }

    public function test_png_and_webp_are_accepted(): void
    {
        [, $form] = $this->photoForm();

        $this->post("/forms/{$form->slug}", ['name' => 'A', 'photo' => UploadedFile::fake()->image('a.png', 10, 10)])->assertSessionHasNoErrors();
        if (function_exists('imagewebp')) {
            $this->post("/forms/{$form->slug}", ['name' => 'B', 'photo' => UploadedFile::fake()->image('b.webp', 10, 10)])->assertSessionHasNoErrors();
        }
        $this->assertGreaterThanOrEqual(1, FormSubmissionFile::count());
        $this->assertTrue(FormSubmissionFile::query()->where('mime_type', 'image/png')->exists());
    }

    public function test_invalid_mime_executable_svg_and_disallowed_types_are_rejected(): void
    {
        [, $form] = $this->photoForm(['allowed_types' => ['jpg']]);
        $bag = 'form_'.$form->id;

        $uploads = [
            'php disguised as jpg' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),
            'html disguised as png' => UploadedFile::fake()->createWithContent('page.png', '<html><script>alert(1)</script></html>'),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'png when only jpg is allowed' => UploadedFile::fake()->image('a.png', 10, 10),
            'plain text' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
            'exe' => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00binary"),
        ];

        foreach ($uploads as $case => $file) {
            $this->post("/forms/{$form->slug}", ['name' => 'X', 'photo' => $file])
                ->assertSessionHasErrorsIn($bag, ['photo']);
            $this->assertSame(0, FormSubmission::count(), "Accepted: {$case}");
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_images_are_rejected(): void
    {
        [, $form] = $this->photoForm(['max_size_kb' => 100]);

        $this->post("/forms/{$form->slug}", ['name' => 'X', 'photo' => UploadedFile::fake()->image('big.jpg', 50, 50)->size(500)])
            ->assertSessionHasErrorsIn('form_'.$form->id, ['photo']);
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_required_and_optional_image_fields(): void
    {
        [, $form] = $this->photoForm();
        $this->post("/forms/{$form->slug}", ['name' => 'X'])->assertSessionHasErrorsIn('form_'.$form->id, ['photo']);

        [, $optional] = $this->photoForm([], required: false);
        $this->post("/forms/{$optional->slug}", ['name' => 'X'])->assertSessionHasNoErrors();
        $value = FormSubmission::sole()->values()->where('field_key', 'photo')->sole();
        $this->assertNull($value->value);
    }

    public function test_stored_files_are_removed_when_the_submission_fails_to_save(): void
    {
        [, $form] = $this->photoForm();

        FormSubmissionFile::created(function () {
            throw new RuntimeException('Simulated failure after the file was written');
        });

        $this->withoutExceptionHandling();
        try {
            $this->post("/forms/{$form->slug}", ['name' => 'X', 'photo' => UploadedFile::fake()->image('a.jpg', 10, 10)]);
            $this->fail('Expected failure');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, FormSubmission::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No orphaned upload left on disk.');
    }

    public function test_only_admins_can_view_uploaded_images_and_ids_are_scoped(): void
    {
        [$user, $form] = $this->photoForm();
        [, $other] = $this->photoForm();
        $this->post("/forms/{$form->slug}", ['name' => 'A', 'photo' => UploadedFile::fake()->image('a.jpg', 10, 10)]);
        $this->post("/forms/{$other->slug}", ['name' => 'B', 'photo' => UploadedFile::fake()->image('b.jpg', 10, 10)]);
        $mine = FormSubmissionFile::query()->whereHas('submission', fn ($q) => $q->where('form_id', $form->id))->sole();
        $theirs = FormSubmissionFile::query()->whereHas('submission', fn ($q) => $q->where('form_id', $other->id))->sole();
        $submission = $mine->submission;

        $url = "/admin/forms/{$form->id}/submissions/{$submission->id}/files/{$mine->id}";
        $this->get($url)->assertRedirect('/login');

        $this->actingAs($user)->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($user)->get("/admin/forms/{$form->id}/submissions/{$submission->id}/files/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->get("/admin/forms/{$other->id}/submissions/{$submission->id}/files/{$mine->id}")->assertNotFound();

        $this->actingAs($user)->get("/admin/forms/{$form->id}/submissions/{$submission->id}")->assertOk()
            ->assertSee('<img src="http://localhost'.$url.'"', false)
            ->assertDontSee($mine->path);
    }

    public function test_export_contains_an_authorized_link_not_the_image_or_its_path(): void
    {
        [$user, $form] = $this->photoForm();
        $this->post("/forms/{$form->slug}", ['name' => 'A', 'photo' => UploadedFile::fake()->image('a.jpg', 10, 10)]);
        $file = FormSubmissionFile::sole();

        $response = $this->actingAs($user)->get("/admin/forms/{$form->id}/submissions/export.xlsx")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'img-export').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
        @unlink($path);

        $this->assertSame(['Submission ID', 'Submitted At', 'Submitted By', 'Name', 'Photo'], $rows[0]);
        $this->assertSame("http://localhost/admin/forms/{$form->id}/submissions/{$file->submission->id}/files/{$file->id}", $rows[1][4]);
        $this->assertStringNotContainsString($file->path, implode('|', array_map('strval', $rows[1])));
    }

    public function test_public_form_renders_a_multipart_file_input(): void
    {
        [, $form] = $this->photoForm(['allowed_types' => ['jpg', 'webp']]);

        $this->get("/forms/{$form->slug}")->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file" name="photo" accept="image/jpeg,image/webp"', false);
    }
}
