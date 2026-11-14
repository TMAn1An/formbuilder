<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An image in the shared media library (public content): uploaded from the
 * page builder or the form builder. Stored on
 * config('formbuilder.storage.media_disk'); referenced by id from page Image
 * blocks, form Image blocks and form banners, exposed publicly only by its
 * random `uuid`. Never deleted by removing a block or banner.
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

    /** The shape both builders' media pickers use. @return array<string, mixed> */
    public function toBuilderArray(): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'name' => $this->original_name, 'width' => $this->width, 'height' => $this->height];
    }
}
