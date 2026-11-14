<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * "Show one section at a time" (settings.multi_step). Paging is done in the
 * browser by form-runtime.js; the server's part is the setting itself, the
 * data-ff-steps hook on the form, and accepting the whole form in one
 * submission exactly as before.
 */
class FormStepsTest extends TestCase
{
    use BuildsForms;

    /** @return list<array<string, mixed>> */
    private function steppedFields(): array
    {
        return [
            $this->fieldDef('section', 'about_you', 'About you'),
            $this->fieldDef('text', 'name', 'Name', ['required' => true]),
            $this->fieldDef('section', 'event', 'Event'),
            $this->fieldDef('select', 'role', 'Role', ['required' => true, 'settings' => ['options' => [['label' => 'Volunteer', 'value' => 'volunteer'], ['label' => 'Speaker', 'value' => 'speaker']]]]),
        ];
    }

    public function test_the_setting_is_off_by_default_and_saved_as_a_boolean(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);
        $this->assertFalse($form->fresh()->setting('multi_step', false));

        $this->saveDefinition($user, $form, $this->steppedFields(), ['settings' => ['multi_step' => 'maybe']])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.multi_step']);

        $this->saveDefinition($user, $form, $this->steppedFields(), ['settings' => ['multi_step' => true]])
            ->assertOk()->assertJsonPath('state.form.settings.multi_step', true);
        $this->assertTrue($form->fresh()->setting('multi_step'));

        $this->saveDefinition($user, $form->fresh(), $this->fieldsAsPayload($form), ['settings' => ['multi_step' => false]])
            ->assertOk()->assertJsonPath('state.form.settings.multi_step', false);
    }

    public function test_a_step_by_step_form_is_marked_for_the_runtime(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, $this->steppedFields(), ['settings' => ['multi_step' => true]]);

        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]+/forms/'.$form->slug.'" enctype="multipart/form-data"\s+data-ff-steps\s*>#', $html);
        $this->assertStringNotContainsString('data-ff-preview', $html);
        // Every field is still in the page (no-JS fallback); the runtime pages them.
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="role"', $html);
        $this->assertStringContainsString('form-runtime.js', $html);
    }

    public function test_a_normal_form_is_unchanged(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, $this->steppedFields());

        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ff-steps', $html);
    }

    public function test_the_admin_preview_is_paged_but_does_not_insist_on_answers(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, $this->steppedFields(), ['settings' => ['multi_step' => true]]);

        $html = $this->actingAs($user)->get("/admin/forms/{$form->id}/preview")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form [^>]*data-ff-preview\s+data-ff-steps\s*>#', $html);
    }

    public function test_the_whole_form_is_still_submitted_and_validated_in_one_request(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, $this->steppedFields(), ['settings' => ['multi_step' => true]]);

        // A missing answer from a later step is caught by the server as usual.
        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha'])->assertSessionHasErrorsIn('form_'.$form->id, ['role']);
        $this->assertSame(0, FormSubmission::count());

        $this->post('/forms/'.$form->slug, ['name' => 'Ayesha', 'role' => 'speaker'])->assertSessionHasNoErrors();
        $values = FormSubmission::sole()->values()->pluck('value', 'field_key')->all();
        $this->assertSame(['name' => 'Ayesha', 'role' => 'speaker'], $values);
    }

    public function test_after_a_server_error_the_field_is_marked_so_its_step_opens(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, $this->steppedFields(), ['settings' => ['multi_step' => true]]);

        $this->from('/forms/'.$form->slug)->post('/forms/'.$form->slug, ['name' => 'Ayesha'])->assertRedirect('/forms/'.$form->slug);
        $html = $this->get('/forms/'.$form->slug)->assertOk()->getContent();
        // form-runtime.js opens the step holding the first .has-error field.
        $this->assertMatchesRegularExpression('#class="ff-field ff-field--select\s+has-error" data-ff-key="role"#', $html);
        $this->assertStringContainsString('value="Ayesha"', $html, 'Answers from earlier steps are kept.');
    }
}
