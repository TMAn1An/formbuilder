<?php

namespace TMAn1An\FormBuilder\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use TMAn1An\FormBuilder\Contracts\Authorizer;
use TMAn1An\FormBuilder\Models\Form;

/**
 * Form authorization. Role decisions are delegated to the host's
 * Authorizer (config('formbuilder.authorizer')); the package only adds the
 * lifecycle rules (archived forms are read-only). There is no delete
 * ability: no route can delete a form or a submission.
 *
 *   canAccess:            list/view/preview/create/edit/publish/deactivate/
 *                         duplicate forms, view & export submissions
 *   canArchive:           archive/restore
 *   canManageCustomCode:  custom CSS / custom HTML / stored-only custom JS
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
        return $this->authorizer->canAccess($user) && $form->status->isEditable();
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

    public function export(Authenticatable $user, Form $form): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function manageCustomCode(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user) && $this->authorizer->canManageCustomCode($user);
    }
}
