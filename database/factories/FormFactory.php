<?php

namespace TMAn1An\FormBuilder\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use TMAn1An\FormBuilder\Enums\FormStatus;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Services\Forms\FormSettingsSchema;
use TMAn1An\FormBuilder\Styling\FormStyleSchema;

/** @extends Factory<Form> */
class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => null,
            'status' => FormStatus::Draft,
            'settings' => FormSettingsSchema::normalize(null),
            'style_settings' => FormStyleSchema::defaults(),
            'lock_version' => 1,
            'created_by' => fn () => config('formbuilder.user_model')::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => FormStatus::Active, 'published_at' => now()]);
    }
}
