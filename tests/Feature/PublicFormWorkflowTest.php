<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use TMAn1An\FormBuilder\FormBuilder;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/** Public form URLs, View live, Copy public link and admin preview. */
class PublicFormWorkflowTest extends TestCase
{
    use BuildsForms;

    public function test_published_form_is_public_and_list_offers_view_live_and_copy_link(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')], ['name' => 'Volunteer Signup']);
        $url = 'http://localhost/forms/'.$form->slug;

        $this->assertSame($url, app(FormBuilder::class)->public('forms.show', ['form' => $form->slug]));
        $this->get('/forms/'.$form->slug)->assertOk()->assertSee('Volunteer Signup');

        $this->actingAs($user)->get('/admin/forms')->assertOk()
            ->assertSee('href="'.$url.'" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">View live</a>', false)
            ->assertSee('data-fba-copy="'.$url.'"', false)
            ->assertSee('/admin/forms/'.$form->id.'/preview', false)
            ->assertSee('/admin/forms/'.$form->id.'/submissions/export.xlsx', false);
        $this->actingAs($user)->get('/admin/forms/'.$form->id.'/edit')->assertOk()->assertSee('View live')->assertSee('data-fba-copy="'.$url.'"', false);
    }

    public function test_draft_form_has_no_view_live_and_is_not_public_but_admin_preview_works(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user, 'Draft Signup');
        $this->saveDefinition($user, $form, [$this->fieldDef('text', 'name', 'Name')])->assertOk();

        $this->actingAs($user)->get('/admin/forms')->assertOk()->assertDontSee('>View live</a>', false)->assertSee('data-fba-copy=', false);
        $this->actingAs($user)->get("/admin/forms/{$form->id}/preview")->assertOk()->assertSee('Draft Signup')->assertSee('Submissions are disabled here');

        auth()->logout();
        $this->get("/forms/{$form->slug}")->assertNotFound();
        $this->get("/admin/forms/{$form->id}/preview")->assertRedirect('/login');
    }

    public function test_private_published_form_is_not_offered_as_view_live(): void
    {
        $user = User::factory()->create();
        $form = $this->publishedForm($user, [$this->fieldDef('text', 'name', 'Name')], ['settings' => ['visibility' => 'private']]);

        $this->actingAs($user)->get('/admin/forms')->assertOk()->assertDontSee('>View live</a>', false)->assertSee('Private');
        auth()->logout();
        $this->get("/forms/{$form->slug}")->assertNotFound();
    }

    public function test_package_assets_are_served_and_path_traversal_is_blocked(): void
    {
        $this->get('/formbuilder-assets/css/forms.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/formbuilder-assets/js/form-runtime.js?v=1')->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get('/formbuilder-assets/../composer.json')->assertNotFound();
        $this->get('/formbuilder-assets/css/missing.css')->assertNotFound();
    }
}
