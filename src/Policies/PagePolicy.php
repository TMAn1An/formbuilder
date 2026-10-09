<?php

namespace TMAn1An\FormBuilder\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use TMAn1An\FormBuilder\Contracts\Authorizer;
use TMAn1An\FormBuilder\Models\Page;

/** Same role model as FormPolicy, for pages. Archived pages are read-only; no delete ability exists. */
class PagePolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function view(Authenticatable $user, Page $page): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function update(Authenticatable $user, Page $page): bool
    {
        return $this->authorizer->canAccess($user) && $page->status->isEditable();
    }

    public function publish(Authenticatable $user, Page $page): bool
    {
        return $this->update($user, $page);
    }

    public function deactivate(Authenticatable $user, Page $page): bool
    {
        return $this->update($user, $page);
    }

    public function archive(Authenticatable $user, Page $page): bool
    {
        return $this->authorizer->canArchive($user) && $page->status->isEditable();
    }

    public function restore(Authenticatable $user, Page $page): bool
    {
        return $this->authorizer->canArchive($user) && ! $page->status->isEditable();
    }

    public function duplicate(Authenticatable $user, Page $page): bool
    {
        return $this->authorizer->canAccess($user);
    }

    public function manageCustomCode(Authenticatable $user): bool
    {
        return $this->authorizer->canAccess($user) && $this->authorizer->canManageCustomCode($user);
    }
}
