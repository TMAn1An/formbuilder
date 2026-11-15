<?php

namespace TMAn1An\FormBuilder\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TMAn1An\FormBuilder\Database\Factories\FormFactory;
use TMAn1An\FormBuilder\Enums\FormStatus;

/**
 * A general-purpose dynamic form. Independent of the certificate/QR
 * systems. Every write goes through TMAn1An\FormBuilder\Services\Forms\FormBuilderService
 * (validated + whitelisted by FormDefinitionValidator first) -- never
 * straight from request input. See docs/FORMS.md.
 */
class Form extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return FormFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'settings',
        'style_settings',
        'custom_css',
        'custom_html_before',
        'custom_html_after',
        'custom_js',
        'lock_version',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'settings' => 'array',
            'style_settings' => 'array',
            'lock_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** Every field including archived ones, in builder order. @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<FormField, $this> */
    public function activeFields(): HasMany
    {
        return $this->fields()->where('is_active', true);
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Co-owners: admins given full control of this form besides its creator
     * (form_owners). @return BelongsToMany<Model, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(config('formbuilder.user_model'), 'form_owners', 'form_id', 'user_id')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /** Whether this user created the form or is one of its co-owners. */
    public function isOwnedBy(Authenticatable $user): bool
    {
        $id = $user->getAuthIdentifier();

        return (string) $this->created_by === (string) $id
            || $this->owners()->whereKey($id)->exists();
    }

    /** Page Form blocks that embed this form. @return HasMany<PageBlock, $this> */
    public function pageBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('formbuilder.user_model'), 'created_by');
    }

    /** @return BelongsTo<Model, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(config('formbuilder.user_model'), 'updated_by');
    }

    /** @param  Builder<Form>  $query */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('status', '!=', FormStatus::Archived);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** The HTML id every scoped style/custom CSS rule for this form hangs off. */
    public function wrapperId(): string
    {
        return 'ff-form-'.$this->id;
    }

    /** Public display title (falls back to the internal name). */
    public function title(): string
    {
        $title = trim((string) $this->setting('title', ''));

        return $title !== '' ? $title : $this->name;
    }
}
