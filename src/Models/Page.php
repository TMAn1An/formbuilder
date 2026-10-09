<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TMAn1An\FormBuilder\Enums\PageStatus;

/**
 * A builder page. Every write goes through Services\Pages\PageBuilderService
 * after PageDefinitionValidator has validated and whitelisted the payload.
 */
class Page extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'status',
        'settings',
        'style_settings',
        'custom_css',
        'lock_version',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'settings' => 'array',
            'style_settings' => 'array',
            'lock_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** All blocks, flat, in render order. @return HasMany<PageBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Model, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('formbuilder.user_model'), 'created_by');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** The HTML id custom CSS for this page is scoped to. */
    public function wrapperId(): string
    {
        return 'pb-page-'.$this->id;
    }
}
