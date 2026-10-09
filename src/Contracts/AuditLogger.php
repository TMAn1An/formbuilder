<?php

namespace TMAn1An\FormBuilder\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use TMAn1An\FormBuilder\Enums\AuditEvent;

/**
 * Receives every significant builder action (form/page created, updated,
 * published, ...). The package ships a no-op default; hosts with an audit
 * trail bind an adapter via config('formbuilder.audit_logger').
 */
interface AuditLogger
{
    /** @param  array<string, mixed>  $metadata */
    public function record(AuditEvent $event, Model $subject, Authenticatable $actor, string $summary, array $metadata = []): void;
}
