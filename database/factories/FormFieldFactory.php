<?php

namespace TMAn1An\FormBuilder\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;

/** @extends Factory<FormField> */
class FormFieldFactory extends Factory
{
    protected $model = FormField::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'label' => 'Full name',
            'key' => 'full_name_'.fake()->unique()->numberBetween(1, 99999),
            'type' => FormFieldType::Text,
            'required' => false,
            'sort_order' => 1,
            'settings' => [],
            'style_settings' => [],
            'conditional_rules' => null,
            'is_active' => true,
        ];
    }
}
