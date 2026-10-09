<?php

namespace TMAn1An\FormBuilder\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use TMAn1An\FormBuilder\Contracts\AuditLogger;
use TMAn1An\FormBuilder\Enums\AuditEvent;

/** Captures audit events so tests can assert what a host's audit trail would receive. */
class RecordingAuditLogger implements AuditLogger
{
    /** @var list<array{event: AuditEvent, subject: Model, summary: string, metadata: array}> */
    public array $records = [];

    public function record(AuditEvent $event, Model $subject, Authenticatable $actor, string $summary, array $metadata = []): void
    {
        $this->records[] = ['event' => $event, 'subject' => $subject, 'summary' => $summary, 'metadata' => $metadata];
    }

    /** @return list<AuditEvent> */
    public function eventsFor(Model $subject): array
    {
        return array_values(array_map(
            fn ($r) => $r['event'],
            array_filter($this->records, fn ($r) => $subject::class === $r['subject']::class && $r['subject']->getKey() === $subject->getKey()),
        ));
    }
}
