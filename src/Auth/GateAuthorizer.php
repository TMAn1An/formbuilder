<?php

namespace TMAn1An\FormBuilder\Auth;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use TMAn1An\FormBuilder\Contracts\Authorizer;

/**
 * Default authorizer: delegates to these Gates when the host defines them,
 * otherwise falls back to safe defaults --
 *
 *   formbuilder.access       default: any authenticated user
 *   formbuilder.custom-code  default: nobody
 *   formbuilder.archive      default: nobody
 *
 * e.g. in a host's AppServiceProvider:
 *   Gate::define('formbuilder.custom-code', fn ($user) => $user->is_admin);
 */
class GateAuthorizer implements Authorizer
{
    public function __construct(private readonly Gate $gate) {}

    public function canAccess(Authenticatable $user): bool
    {
        return $this->check('formbuilder.access', $user, true);
    }

    public function canManageCustomCode(Authenticatable $user): bool
    {
        return $this->check('formbuilder.custom-code', $user, false);
    }

    public function canArchive(Authenticatable $user): bool
    {
        return $this->check('formbuilder.archive', $user, false);
    }

    private function check(string $ability, Authenticatable $user, bool $default): bool
    {
        return $this->gate->has($ability) ? $this->gate->forUser($user)->allows($ability) : $default;
    }
}
