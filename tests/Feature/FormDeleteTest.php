<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use TMAn1An\FormBuilder\Enums\AuditEvent;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\FormSubmissionFile;
use TMAn1An\FormBuilder\Models\FormSubmissionValue;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\BuildsPages;
use TMAn1An\FormBuilder\Tests\Fixtures\RecordingAuditLogger;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Deleting a form: its creator or a Super Admin (canArchive) may; it removes
 * the fields, every submission and their stored files; pages embedding it
 * are left without the form.
 */
class FormDeleteTest extends TestCase
{
    use BuildsForms, BuildsPages;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_the_creator_and_super_admins_can_delete_others_cannot(): void
    {
        $creator = User::factory()->create();
        $colleague = User::factory()->create();
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $mine = $this->createFormVia($creator, 'Creator form');
        $theirs = $this->createFormVia($colleague, 'Colleague form');

        // The list offers Delete only where it is allowed.
        $html = $this->actingAs($creator)->get('/admin/forms')->assertOk()->getContent();
        $this->assertStringContainsString('action="http://localhost/admin/forms/'.$mine->id.'"', $html);
        $this->assertStringNotContainsString('action="http://localhost/admin/forms/'.$theirs->id.'"', $html);

        $this->actingAs($creator)->delete("/admin/forms/{$theirs->id}")->assertForbidden();
        $this->assertNotNull($theirs->fresh());

        $this->actingAs($creator)->delete("/admin/forms/{$mine->id}")
            ->assertRedirect('/admin/forms')->assertSessionHas('status', '"Creator form" and its submissions were deleted.');
        $this->assertNull($mine->fresh());

        // A Super Admin can delete anyone's form.
        $this->actingAs($superAdmin)->get('/admin/forms')->assertSee('action="http://localhost/admin/forms/'.$theirs->id.'"', false);
        $this->actingAs($superAdmin)->delete("/admin/forms/{$theirs->id}")->assertRedirect('/admin/forms');
        $this->assertSame(0, Form::count());

        auth()->logout();
        $other = $this->createFormVia($creator, 'Third');
        auth()->logout();
        $this->delete("/admin/forms/{$other->id}")->assertRedirect('/login');
        $this->assertNotNull($other->fresh());
    }

    public function test_deleting_removes_fields_submissions_and_files_and_unlinks_pages(): void
    {
        $creator = User::factory()->create();
        $form = $this->publishedForm($creator, [
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
            $this->fieldDef('image', 'photo', 'Photo'),
        ], ['name' => 'Volunteer drive']);
        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha', 'photo' => UploadedFile::fake()->image('a.jpg', 30, 30)])->assertSessionHasNoErrors();
        $this->post('/forms/'.$form->slug, ['name' => 'Rafi'])->assertSessionHasNoErrors();
        $file = FormSubmissionFile::sole();
        Storage::disk('local')->assertExists($file->path);

        $page = $this->createPageVia($creator, 'Join us');
        $this->savePage($creator, $page, [$this->block('f', 'form', ['form_id' => $form->id])])->assertOk();
        $keep = $this->publishedForm($creator, [$this->fieldDef('text', 'x', 'X')], ['name' => 'Keep me']);
        $this->post('/forms/'.$keep->slug, ['x' => 'still here'])->assertSessionHasNoErrors();

        // The confirmation states what goes with it.
        $this->actingAs($creator)->get('/admin/forms')->assertOk()
            ->assertSee('Its 2 submissions (answers and uploaded images) will be deleted too.', false)
            ->assertSee('Type DELETE to confirm.', false);

        $this->actingAs($creator)->delete("/admin/forms/{$form->id}")->assertRedirect('/admin/forms');

        $this->assertNull($form->fresh());
        $this->assertSame(0, FormField::where('form_id', $form->id)->count());
        $this->assertSame(1, FormSubmission::count(), 'Only the other form\'s submission is left.');
        $this->assertSame(1, FormSubmissionValue::count());
        $this->assertSame(0, FormSubmissionFile::count());
        Storage::disk('local')->assertMissing($file->path);
        $this->assertNull(PageBlock::where('page_id', $page->id)->sole()->form_id);
        $this->assertNotNull($keep->fresh());

        $record = collect(app(RecordingAuditLogger::class)->records)->firstWhere('event', AuditEvent::FormDeleted);
        $this->assertSame(['slug' => $form->slug, 'submissions' => 2, 'pages_embedding' => 1], $record['metadata']);

        // The public URL is gone; the page that embedded it still renders.
        $this->get('/forms/'.$form->slug)->assertNotFound();
    }

    public function test_an_empty_form_only_asks_for_a_plain_confirmation(): void
    {
        $creator = User::factory()->create();
        $form = $this->createFormVia($creator, 'Draft idea');

        $html = $this->actingAs($creator)->get('/admin/forms')->assertOk()->getContent();
        // Js::from() writes the quotes as \u0022 inside the onsubmit handler.
        $this->assertStringContainsString('Delete the form \\u0022Draft idea\\u0022 for good? This cannot be undone.', $html);
        $this->assertStringNotContainsString('Type DELETE', $html);

        // The builder toolbar offers it too.
        $this->actingAs($creator)->get("/admin/forms/{$form->id}/edit")->assertOk()->assertSee('action="http://localhost/admin/forms/'.$form->id.'"', false);
    }
}
