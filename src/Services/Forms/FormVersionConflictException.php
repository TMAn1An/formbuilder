<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use RuntimeException;

/** A builder (form or page) tried to save on top of a version someone else already replaced. */
final class FormVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('This was changed somewhere else (another tab or another admin) since you opened it.');
    }
}
