<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TMAn1An\FormBuilder\Enums\PageBlockType;

/**
 * One page block. `form_id` / `media_id` are real foreign keys (the page
 * references a form or an image, it never copies them); everything else
 * lives in the type-whitelisted `settings` JSON.
 */
class PageBlock extends Model
{
    protected $fillable = [
        'page_id',
        'parent_id',
        'slot',
        'type',
        'sort_order',
        'settings',
        'form_id',
        'media_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => PageBlockType::class,
            'settings' => 'array',
            'slot' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<PageMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PageMedia::class, 'media_id');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
