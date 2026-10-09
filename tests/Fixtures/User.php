<?php

namespace TMAn1An\FormBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Minimal host user for package tests. `is_admin` stands in for a host's "super admin" role. */
class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'is_admin'];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
