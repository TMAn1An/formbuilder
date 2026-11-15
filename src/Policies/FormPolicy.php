<?php

namespace TMAn1An\FormBuilder\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use TMAn1An\FormBuilder\Contracts\Authorizer;
use TMAn1An\FormBuilder\Models\Form;

/**
 * Form authorization. Role decisions are delegated to the host's
 * Authorizer (config('formbuilder.authorizer')); the package adds form
 * ownership and the lifecycle rules (archived forms are read-only).
 *
 *   canAccess:            list/view/preview/create/duplicate forms, view &
 *                         export submissions (everyone with builder access)
 *   manages(form):        edit/publish/deactivate, edit & delete submissions,
 *                         delete the form, manage co-owners -- the form's
 *                         creator, its co-owners (form_owners), or anyone
 *                         with canArchive (Super Admin on the IEEE site)
 *   canArchive:           archive/restore
 *   canManageCustomCode:  custom CSS / custom HTML / stored-only custom JS
 *
 * Everyone else with builder access sees other people's forms read-only.
 */
class FormPolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function view(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function update(Authenticatable $user, Form $form): bool
    {
        return $this->manages($user, $form) && $form->status->isEditable();
    }

    public function publish(Authenticatable $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    public function deactivate(Authenticatable $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    public function archive(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canArchive($user) && $form->status->isEditable();
    }

    /** Delete a form for good, with its submissions. */
    public function delete(Authenticatable $user, Form $form): bool
    {
        return $this->manages($user, $form);
    }

    /** Add or remove co-owners. */
    public function share(Authenticatable $user, Form $form): bool
    {
        return $this->manages($user, $form);
    }

    public function restore(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canArchive($user) && ! $form->status->isEditable();
    }

    public function duplicate(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function viewSubmissions(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canAccess($user);
    }

    /** Correct a stored submission's answers. */
    public function editSubmissions(Authenticatable $user, Form $form): bool
    {
        return $this->manages($user, $form);
    }

    /** Delete a submission for good. */
    public function deleteSubmissions(Authenticatable $user, Form $form): bool
    {
        return $this->manages($user, $form);
    }

    public function export(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function manageCustomCode(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user) && $this->authorizer->canManageCustomCode($user);
    }

    /** Creator, co-owner, or a user the host lets archive (Super Admin). */
    public function manages(Authenticatable $user, Form $form): bool
    {
        if (! $this->authorizer->canAccess($user)) {
            return false;
        }

        return $this->authorizer->canArchive($user) || $form->isOwnedBy($user);
    }
}
