<?php

namespace TMAn1An\FormBuilder\Enums;

/** Builder actions reported to the host's AuditLogger. Values are stable identifiers hosts may store. */
enum AuditEvent: string
{
    case FormCreated = 'form_created';
    case FormUpdated = 'form_updated';
    case FormPublished = 'form_published';
    case FormDeactivated = 'form_deactivated';
    case FormArchived = 'form_archived';
    case FormRestored = 'form_restored';
    case FormDuplicated = 'form_duplicated';
    case FormDeleted = 'form_deleted';
    case FormOwnerAdded = 'form_owner_added';
    case FormOwnerRemoved = 'form_owner_removed';
    // Recorded with the form as subject; metadata names the submission.
    case SubmissionUpdated = 'form_submission_updated';
    case SubmissionDeleted = 'form_submission_deleted';

    case PageCreated = 'page_created';
    case PageUpdated = 'page_updated';
    case PagePublished = 'page_published';
    case PageDeactivated = 'page_deactivated';
    case PageArchived = 'page_archived';
    case PageRestored = 'page_restored';
    case PageDuplicated = 'page_duplicated';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
