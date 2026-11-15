<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use TMAn1An\FormBuilder\Enums\AuditEvent;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\FormSubmissionFile;
use TMAn1An\FormBuilder\Models\FormSubmissionValue;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\Fixtures\RecordingAuditLogger;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * "Unique" fields (one submission per value, e.g. a student ID) and admin
 * edit / delete of stored submissions.
 */
class SubmissionManagementTest extends TestCase
{
    use BuildsForms;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function studentForm(User $admin, string $name = 'Membership 2026'): Form
    {
        return $this->publishedForm($admin, [
            $this->fieldDef('text', 'student_id', 'Student ID', ['required' => true, 'settings' => ['unique' => true]]),
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
            $this->fieldDef('email', 'email', 'Email', ['settings' => ['unique' => true]]),
            $this->fieldDef('select', 'dept', 'Department', ['settings' => ['options' => [['label' => 'EEE', 'value' => 'eee'], ['label' => 'CSE', 'value' => 'cse']]]]),
        ], ['name' => $name]);
    }

    private function submit(Form $form, array $data)
    {
        return $this->post('/forms/'.$form->slug, $data);
    }

    public function test_the_unique_setting_is_saved_and_offered_only_for_suitable_types(): void
    {
        $admin = User::factory()->create();
        $form = $this->createFormVia($admin);

        $this->saveDefinition($admin, $form, [
            $this->fieldDef('text', 'student_id', 'Student ID', ['settings' => ['unique' => true]]),
            $this->fieldDef('long_text', 'about', 'About', ['settings' => ['unique' => true]]), // not offered for long text: dropped
        ])->assertOk();

        $fields = $form->fresh()->fields()->get()->keyBy('key');
        $this->assertTrue($fields['student_id']->settings['unique']);
        $this->assertArrayNotHasKey('unique', $fields['about']->settings ?? []);
    }

    public function test_a_unique_value_can_only_be_submitted_once_per_form(): void
    {
        $admin = User::factory()->create();
        $form = $this->studentForm($admin);
        $bag = 'form_'.$form->id;

        $this->submit($form, ['student_id' => '22303142', 'name' => 'Ayesha'])->assertSessionHasNoErrors();

        // Same ID again (also with spaces / different letter case) is refused, with the reason on the field.
        foreach (['22303142', ' 22303142 '] as $again) {
            $this->submit($form, ['student_id' => $again, 'name' => 'Someone else'])
                ->assertSessionHasErrorsIn($bag, ['student_id' => 'This Student ID has already been used to submit this form.']);
        }
        $this->submit($form, ['student_id' => 'AB-1', 'name' => 'A', 'email' => 'Rafi@iubat.edu'])->assertSessionHasNoErrors();
        $this->submit($form, ['student_id' => 'ab-1', 'name' => 'B'])->assertSessionHasErrorsIn($bag, ['student_id']);
        $this->submit($form, ['student_id' => 'X9', 'name' => 'C', 'email' => 'rafi@IUBAT.edu'])->assertSessionHasErrorsIn($bag, ['email']);

        // A new ID goes through; an empty optional unique field is never checked.
        $this->submit($form, ['student_id' => '22303999', 'name' => 'Rafi'])->assertSessionHasNoErrors();
        $this->submit($form, ['student_id' => '22303998', 'name' => 'Nabila'])->assertSessionHasNoErrors();

        $this->assertSame(4, FormSubmission::count());

        // Uniqueness is per form: another form may reuse the same ID.
        $other = $this->studentForm($admin, 'Workshop 2026');
        $this->submit($other, ['student_id' => '22303142', 'name' => 'Ayesha'])->assertSessionHasNoErrors();
        $this->assertSame(5, FormSubmission::count());
    }

    public function test_admin_can_edit_a_submission_and_the_change_is_recorded(): void
    {
        $admin = User::factory()->create();
        $form = $this->studentForm($admin);
        $this->submit($form, ['student_id' => '100', 'name' => 'Ayesha', 'dept' => 'eee'])->assertSessionHasNoErrors();
        $submission = FormSubmission::sole();

        $this->actingAs($admin)->get("/admin/forms/{$form->id}/submissions/{$submission->id}/edit")
            ->assertOk()->assertSee('value="Ayesha"', false)->assertSee('Edit submission #'.$submission->id);

        $this->actingAs($admin)->put("/admin/forms/{$form->id}/submissions/{$submission->id}", [
            'student_id' => '100', 'name' => 'Ayesha Rahman', 'email' => 'ayesha@iubat.edu', 'dept' => 'cse',
            'not_a_field' => 'ignored',
        ])->assertRedirect()->assertSessionHas('status', 'Submission updated (3 answers).');

        $values = $submission->values()->get()->keyBy('field_key');
        $this->assertSame('Ayesha Rahman', $values['name']->value);
        $this->assertSame('ayesha@iubat.edu', $values['email']->value);
        $this->assertSame('cse', $values['dept']->value);
        $this->assertSame('CSE', $values['dept']->display_value, 'Option label kept in the display value.');
        $this->assertArrayNotHasKey('not_a_field', $values->all());

        // The detail page shows the edit (and renders with edit history present).
        $this->actingAs($admin)->get("/admin/forms/{$form->id}/submissions/{$submission->id}")
            ->assertOk()->assertSee('Ayesha Rahman')->assertSee('1 time, last');

        $edits = $submission->fresh()->metadata['edits'];
        $this->assertSame([$admin->id], array_column($edits, 'by'));
        $this->assertSame(['name', 'email', 'dept'], $edits[0]['fields']);

        $audit = app(RecordingAuditLogger::class);
        $this->assertContains(AuditEvent::SubmissionUpdated, $audit->eventsFor($form));
        $this->assertSame(['submission_id' => $submission->id, 'fields' => ['name', 'email', 'dept']], end($audit->records)['metadata']);
    }

    public function test_edits_are_validated_and_unique_values_cannot_collide(): void
    {
        $admin = User::factory()->create();
        $form = $this->studentForm($admin);
        $this->submit($form, ['student_id' => '100', 'name' => 'A'])->assertSessionHasNoErrors();
        $this->submit($form, ['student_id' => '200', 'name' => 'B'])->assertSessionHasNoErrors();
        [$first, $second] = FormSubmission::orderBy('id')->get()->all();
        $url = "/admin/forms/{$form->id}/submissions/{$second->id}";

        $this->actingAs($admin)->put($url, ['student_id' => '100', 'name' => 'B'])
            ->assertSessionHasErrors(['student_id' => 'This Student ID has already been used to submit this form.']);
        $this->actingAs($admin)->put($url, ['student_id' => '200', 'name' => 'B', 'email' => 'not-an-email', 'dept' => 'law'])
            ->assertSessionHasErrors(['email', 'dept']);
        $this->assertSame('200', $second->values()->where('field_key', 'student_id')->sole()->value);

        // Keeping its own unique value is fine, and an unchanged save changes nothing.
        $this->actingAs($admin)->put($url, ['student_id' => '200', 'name' => 'B'])->assertSessionHas('status', 'Nothing changed.');
        $this->assertArrayNotHasKey('edits', $second->fresh()->metadata ?? []);
    }

    public function test_only_super_admins_can_delete_and_deleting_removes_everything(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $staff = User::factory()->create();
        $form = $this->publishedForm($superAdmin, [
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
            $this->fieldDef('image', 'photo', 'Photo'),
        ]);
        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha', 'photo' => UploadedFile::fake()->image('me.jpg', 40, 40)])->assertSessionHasNoErrors();
        $submission = FormSubmission::sole();
        $file = FormSubmissionFile::sole();
        Storage::disk('local')->assertExists($file->path);
        $url = "/admin/forms/{$form->id}/submissions/{$submission->id}";

        // Staff can view and edit but don't see or get the delete action.
        $this->actingAs($staff)->get($url)->assertOk()->assertSee('Edit answers')->assertDontSee('Delete submission');
        $this->actingAs($staff)->delete($url)->assertForbidden();
        $this->assertSame(1, FormSubmission::count());

        $this->actingAs($superAdmin)->get($url)->assertSee('Delete submission');
        $this->actingAs($superAdmin)->delete($url)->assertRedirect()->assertSessionHas('status', "Submission #{$submission->id} deleted.");

        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(0, FormSubmissionValue::count());
        $this->assertSame(0, FormSubmissionFile::count());
        Storage::disk('local')->assertMissing($file->path);
        $this->assertContains(AuditEvent::SubmissionDeleted, app(RecordingAuditLogger::class)->eventsFor($form));
    }

    public function test_access_rules_for_edit_and_delete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = $this->studentForm($admin);
        $this->submit($form, ['student_id' => '1', 'name' => 'A'])->assertSessionHasNoErrors();
        $submission = FormSubmission::sole();
        $url = "/admin/forms/{$form->id}/submissions/{$submission->id}";

        auth()->logout();
        $this->get($url.'/edit')->assertRedirect('/login');
        $this->put($url, ['name' => 'X'])->assertRedirect('/login');
        $this->delete($url)->assertRedirect('/login');

        Gate::define('formbuilder.access', fn (User $u) => $u->is_admin);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get($url.'/edit')->assertForbidden();
        $this->actingAs($outsider)->put($url, ['name' => 'X'])->assertForbidden();

        // A submission id from another form is not reachable through this form.
        $other = $this->studentForm($admin, 'Other form');
        $this->actingAs($admin)->get("/admin/forms/{$other->id}/submissions/{$submission->id}/edit")->assertNotFound();
        $this->actingAs($admin)->delete("/admin/forms/{$other->id}/submissions/{$submission->id}")->assertNotFound();
        $this->assertSame('A', $submission->values()->where('field_key', 'name')->sole()->value);
    }
}
