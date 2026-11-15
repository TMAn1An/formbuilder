<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use Illuminate\Support\Facades\DB;
use TMAn1An\FormBuilder\Enums\AuditEvent;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\Fixtures\RecordingAuditLogger;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Form ownership: the creator, co-owners (form_owners) and Super Admins
 * manage a form; other admins can only view it. Managers can add or remove
 * co-owners from existing admins.
 */
class FormOwnersTest extends TestCase
{
    use BuildsForms;

    private function textField(): array
    {
        return [$this->fieldDef('text', 'name', 'Name')];
    }

    public function test_other_admins_can_view_but_not_change_a_form(): void
    {
        $creator = User::factory()->create(['name' => 'Creator']);
        $colleague = User::factory()->create(['name' => 'Colleague']);
        $form = $this->publishedForm($creator, $this->textField(), ['name' => 'Recruitment']);
        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha'])->assertSessionHasNoErrors();
        $submission = FormSubmission::sole();

        // Viewing works: list (View, not Edit), read-only builder, preview, submissions, export.
        $this->actingAs($colleague)->get('/admin/forms')->assertOk()->assertSee('>View</a>', false);
        $this->actingAs($colleague)->get("/admin/forms/{$form->id}/edit")->assertOk()->assertSee('"canEdit":false', false);
        $this->actingAs($colleague)->get("/admin/forms/{$form->id}/preview")->assertOk();
        $this->actingAs($colleague)->get("/admin/forms/{$form->id}/submissions/{$submission->id}")->assertOk()->assertSee('Ayesha');
        $this->actingAs($colleague)->get("/admin/forms/{$form->id}/submissions/export.xlsx")->assertOk();

        // Changing doesn't.
        $this->saveDefinition($colleague, $form, $this->textField(), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($colleague)->post("/admin/forms/{$form->id}/deactivate")->assertForbidden();
        $this->actingAs($colleague)->put("/admin/forms/{$form->id}/submissions/{$submission->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($colleague)->delete("/admin/forms/{$form->id}")->assertForbidden();
        $this->actingAs($colleague)->post("/admin/forms/{$form->id}/owners", ['user_id' => $colleague->id])->assertForbidden();

        $this->assertSame('Recruitment', $form->fresh()->name);
        $this->assertSame(FormStatus::Active, $form->fresh()->status);
        $this->assertSame(0, DB::table('form_owners')->count());
    }

    public function test_the_creator_can_make_another_admin_a_co_owner_with_full_control(): void
    {
        $creator = User::factory()->create(['name' => 'Creator']);
        $colleague = User::factory()->create(['name' => 'Rafi', 'email' => 'rafi@iubat.edu']);
        $third = User::factory()->create(['name' => 'Nabila']);
        $form = $this->publishedForm($creator, $this->textField(), ['name' => 'Recruitment']);

        $this->actingAs($creator)->get("/admin/forms/{$form->id}/owners")->assertOk()
            ->assertSee('Rafi (rafi@iubat.edu)')->assertSee('Add co-owner');
        $this->actingAs($creator)->post("/admin/forms/{$form->id}/owners", ['user_id' => $colleague->id])
            ->assertRedirect()->assertSessionHas('status', 'Rafi (rafi@iubat.edu) is now a co-owner and can manage this form.');

        // The co-owner now manages it: edit, deactivate, and share further.
        $this->saveDefinition($colleague, $form, $this->textField(), ['name' => 'Recruitment 2026'])->assertOk();
        $this->actingAs($colleague)->post("/admin/forms/{$form->id}/deactivate")->assertRedirect();
        $this->actingAs($colleague)->post("/admin/forms/{$form->id}/owners", ['user_id' => $third->id])->assertRedirect();
        $this->assertSame('Recruitment 2026', $form->fresh()->name);
        $this->assertTrue($form->fresh()->isOwnedBy($third));

        $events = app(RecordingAuditLogger::class)->eventsFor($form);
        $this->assertSame(2, count(array_keys($events, AuditEvent::FormOwnerAdded, true)));

        // Removing a co-owner takes the control away again.
        $this->actingAs($creator)->delete("/admin/forms/{$form->id}/owners/{$colleague->id}")
            ->assertRedirect()->assertSessionHas('status', 'Rafi (rafi@iubat.edu) is no longer a co-owner.');
        $this->saveDefinition($colleague, $form->fresh(), $this->textField(), ['name' => 'Again'])->assertForbidden();
        $this->assertContains(AuditEvent::FormOwnerRemoved, app(RecordingAuditLogger::class)->eventsFor($form));

        // The creator can't be "removed"; the route only knows co-owners.
        $this->actingAs($third)->delete("/admin/forms/{$form->id}/owners/{$creator->id}")->assertNotFound();
    }

    public function test_only_eligible_admins_can_be_added(): void
    {
        $creator = User::factory()->create();
        $superAdmin = User::factory()->create(['is_admin' => true, 'name' => 'Boss']);
        $form = $this->createFormVia($creator);

        // Super Admins already manage everything, the creator already owns it, unknown ids don't exist.
        foreach ([$superAdmin->id, $creator->id, 999999] as $id) {
            $this->actingAs($creator)->post("/admin/forms/{$form->id}/owners", ['user_id' => $id])
                ->assertSessionHasErrors(['user_id' => 'Choose an admin from the list.']);
        }
        $this->actingAs($creator)->get("/admin/forms/{$form->id}/owners")->assertDontSee('Boss');
        $this->assertSame(0, DB::table('form_owners')->count());
    }

    public function test_super_admins_manage_every_form_and_deleting_a_form_clears_its_owners(): void
    {
        $creator = User::factory()->create();
        $colleague = User::factory()->create();
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $form = $this->createFormVia($creator, 'Shared form');
        $this->actingAs($creator)->post("/admin/forms/{$form->id}/owners", ['user_id' => $colleague->id])->assertRedirect();

        $this->saveDefinition($superAdmin, $form->fresh(), $this->textField(), ['name' => 'Fixed by admin'])->assertOk();
        $this->actingAs($superAdmin)->post("/admin/forms/{$form->id}/owners", ['user_id' => User::factory()->create()->id])->assertRedirect();
        $this->assertSame(2, DB::table('form_owners')->count());

        // A co-owner can delete the form; its ownership rows go with it.
        $this->actingAs($colleague)->delete("/admin/forms/{$form->id}")->assertRedirect('/admin/forms');
        $this->assertSame(0, Form::count());
        $this->assertSame(0, DB::table('form_owners')->count());
    }
}
