<?php

namespace TMAn1An\FormBuilder\Tests\Feature;

use TMAn1An\FormBuilder\Styling\ButtonStyleSchema;
use TMAn1An\FormBuilder\Styling\FormStyleSchema;
use TMAn1An\FormBuilder\Tests\BuildsForms;
use TMAn1An\FormBuilder\Tests\BuildsPages;
use TMAn1An\FormBuilder\Tests\Fixtures\User;
use TMAn1An\FormBuilder\Tests\TestCase;

/**
 * Submit-button sizing/styling (ButtonStyleSchema) -- shared by the form
 * submit button and the page Button block. The real box changes (width /
 * min-width / min-height / padding), never just the text.
 */
class ButtonStyleTest extends TestCase
{
    use BuildsForms, BuildsPages;

    private function saveButton(User $user, $form, array $button)
    {
        $style = FormStyleSchema::defaults();
        $style['button'] = array_replace($style['button'], $button);

        return $this->saveDefinition($user, $form, [$this->fieldDef('text', 'name', 'Name')], ['style_settings' => $style]);
    }

    public function test_custom_width_padding_height_and_alignment_persist_and_render(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $this->saveButton($user, $form, [
            'width' => 'custom', 'custom_width' => '220px', 'min_height' => '56px', 'min_width' => '10rem',
            'padding_x' => 40, 'padding_y' => 18, 'alignment' => 'center', 'font_size' => 20, 'font_weight' => '700',
            'border_width' => 2, 'border_color' => '#112233', 'radius' => 30,
        ])->assertOk();

        $button = $form->fresh()->style_settings['button'];
        $this->assertSame('custom', $button['width']);
        $this->assertSame('220px', $button['custom_width']);
        $this->assertSame('56px', $button['min_height']);
        $this->assertSame('10rem', $button['min_width']);
        $this->assertSame(40, $button['padding_x']);
        $this->assertSame('center', $button['alignment']);

        // Survives a reload of the builder.
        $this->get("/admin/forms/{$form->id}/edit")->assertOk()
            ->assertSee('"custom_width":"220px"', false)
            ->assertSee('"min_height":"56px"', false);

        // Same CSS in preview and on the public page.
        foreach (["/admin/forms/{$form->id}/preview"] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('--ff-btn-width:min(220px, 100%)', false)
                ->assertSee('--ff-btn-min-height:56px', false)
                ->assertSee('--ff-btn-min-width:10rem', false)
                ->assertSee('--ff-btn-px:40px', false)
                ->assertSee('--ff-btn-py:18px', false)
                ->assertSee('--ff-btn-align:center', false)
                ->assertSee('--ff-btn-size:20px', false)
                ->assertSee('--ff-btn-weight:700', false)
                ->assertSee('--ff-btn-border-width:2px', false)
                ->assertSee('--ff-btn-border-color:#112233', false);
        }

        $this->saveDefinition($user, $form->fresh(), $this->fieldsAsPayload($form), ['intent' => 'publish', 'style_settings' => $form->fresh()->style_settings])->assertOk();
        auth()->logout();
        $this->get("/forms/{$form->slug}")->assertOk()->assertSee('--ff-btn-width:min(220px, 100%)', false);
    }

    public function test_percent_and_rem_custom_widths(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $this->saveButton($user, $form, ['width' => 'custom', 'custom_width' => '50%'])->assertOk();
        $this->get("/admin/forms/{$form->id}/preview")->assertSee('--ff-btn-width:min(50%, 100%)', false);

        $this->saveButton($user, $form, ['width' => 'custom', 'custom_width' => '12.5rem'])->assertOk();
        $this->get("/admin/forms/{$form->id}/preview")->assertSee('--ff-btn-width:min(12.5rem, 100%)', false);
    }

    public function test_full_and_auto_width(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        $this->saveButton($user, $form, ['width' => 'full', 'custom_width' => '300px'])->assertOk();
        $this->get("/admin/forms/{$form->id}/preview")->assertSee('--ff-btn-width:100%', false);

        $this->saveButton($user, $form, ['width' => 'auto'])->assertOk();
        $this->get("/admin/forms/{$form->id}/preview")->assertSee('--ff-btn-width:auto', false);
    }

    public function test_unsafe_or_out_of_range_lengths_are_rejected(): void
    {
        $user = User::factory()->create();
        $form = $this->createFormVia($user);

        foreach (['500vw', '120%', '9999px', 'calc(100% - 1px)', '10px;color:red', '-5px', '200rem'] as $bad) {
            $this->saveButton($user, $form, ['width' => 'custom', 'custom_width' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['style_settings.button.custom_width']);
        }
        $this->saveButton($user, $form, ['min_height' => 'auto'])->assertStatus(422)->assertJsonValidationErrors(['style_settings.button.min_height']);
        $this->saveButton($user, $form, ['width' => 'huge'])->assertStatus(422)->assertJsonValidationErrors(['style_settings.button.width']);
        $this->saveButton($user, $form, ['padding_x' => 500])->assertStatus(422)->assertJsonValidationErrors(['style_settings.button.padding_x']);
    }

    public function test_legacy_button_settings_still_render(): void
    {
        // A form saved before custom sizing existed (only width auto|full etc.).
        $this->assertSame('100%', $this->declaration(['width' => 'full', 'background' => '#00629b'], '--ff-btn-width'));
        $this->assertSame('0px', $this->declaration(['width' => 'full'], '--ff-btn-min-height'));
        // Width = custom but no stored value falls back to the default custom width, never raw input.
        $this->assertSame('min(240px, 100%)', $this->declaration(['width' => 'custom'], '--ff-btn-width'));
    }

    private function declaration(array $button, string $var): string
    {
        foreach (explode(';', ButtonStyleSchema::cssDeclarations($button)) as $decl) {
            [$name, $value] = explode(':', $decl, 2);
            if ($name === $var) {
                return $value;
            }
        }
        $this->fail("{$var} not emitted");
    }

    public function test_page_button_block_uses_the_same_button_style(): void
    {
        $user = User::factory()->create();
        $page = $this->createPageVia($user);

        $this->savePage($user, $page, [
            $this->block('b', 'button', ['text' => 'Register now', 'url' => '/forms/volunteer', 'new_tab' => true,
                'style' => ['width' => 'custom', 'custom_width' => '60%', 'min_height' => '48px', 'alignment' => 'right', 'background' => '#00843d']]),
        ], ['intent' => 'publish'])->assertOk();

        auth()->logout();
        $this->get("/pages/{$page->slug}")->assertOk()
            ->assertSee('class="ff-button"', false)
            ->assertSee('href="/forms/volunteer"', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('--ff-btn-width:min(60%, 100%)', false)
            ->assertSee('--ff-btn-min-height:48px', false)
            ->assertSee('--ff-btn-align:flex-end', false)
            ->assertSee('--ff-btn-bg:#00843d', false)
            ->assertSee('Register now');
    }
}
