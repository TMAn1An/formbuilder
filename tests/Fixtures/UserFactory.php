<?php

namespace TMAn1An\FormBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'not-used',
            'is_admin' => false,
        ];
    }

    /** Equivalent of a host "super admin": custom code + archive. */
    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }
}
