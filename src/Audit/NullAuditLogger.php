<?php

namespace TMAn1An\FormBuilder\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use TMAn1An\FormBuilder\Contracts\AuditLogger;
use TMAn1An\FormBuilder\Enums\AuditEvent;

/** Default: record nothing. Hosts with an audit trail bind their own AuditLogger. */
class NullAuditLogger implements AuditLogger
{
    public function record(AuditEvent $event, Model $subject, Authenticatable $actor, string $summary, array $metadata = []): void {}
}
