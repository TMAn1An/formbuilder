<?php

namespace TMAn1An\FormBuilder\Enums;

/** Only Published pages are publicly reachable. Archived = retired, read-only. */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deactivated = 'deactivated';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Deactivated => 'Deactivated',
            self::Archived => 'Archived',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }
}
