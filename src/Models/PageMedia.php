<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An image uploaded for page building (public content). Stored on
 * config('formbuilder.storage.media_disk'); referenced from Image blocks by
 * id, exposed publicly only by its random `uuid`.
 */
class PageMedia extends Model
{
    protected $table = 'page_media';

    protected $fillable = [
        'uuid',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Public URL: through the package media route (default) or the disk's own URL (needs storage:link). */
    public function url(): string
    {
        if (config('formbuilder.storage.media_delivery') === 'url') {
            return Storage::disk($this->disk)->url($this->path);
        }

        return route('formbuilder.media', ['media' => $this->uuid, 'filename' => basename($this->path)]);
    }
}
