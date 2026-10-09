<?php

namespace TMAn1An\FormBuilder\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The host application's answer to "who may do what" in the builders. The
 * package's policies (FormPolicy, PagePolicy) delegate every role decision
 * here, so the package never needs to know about the host's roles.
 * Bind your implementation via config('formbuilder.authorizer').
 */
interface Authorizer
{
    /** May this user open the builder admin at all (list/create/edit/publish forms and pages, view/export submissions)? */
    public function canAccess(Authenticatable $user): bool;

    /** Custom code: form/page custom CSS, custom HTML before/after a form, stored-only custom JS. */
    public function canManageCustomCode(Authenticatable $user): bool;

    /** Archive / restore forms and pages. */
    public function canArchive(Authenticatable $user): bool;
}
