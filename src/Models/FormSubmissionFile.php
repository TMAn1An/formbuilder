<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An image uploaded with a submission: metadata + storage path only (never the bytes). */
class FormSubmissionFile extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'form_submission_id',
        'form_field_id',
        'field_key',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<FormSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }
}
