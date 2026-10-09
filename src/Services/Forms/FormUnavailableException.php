<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use RuntimeException;
use TMAn1An\FormBuilder\Enums\FormAvailabilityState;

/** The form exists but can't take this submission (closed, limit reached, already submitted, ...). */
final class FormUnavailableException extends RuntimeException
{
    public function __construct(public readonly FormAvailabilityState $state)
    {
        parent::__construct($state->message());
    }
}
