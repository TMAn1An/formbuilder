<?php

namespace TMAn1An\FormBuilder\Services\Forms;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use TMAn1An\FormBuilder\Enums\FormAvailabilityState;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\FormSubmissionValue;

/**
 * Validates and stores one public submission. Validation rules are built
 * from the stored field definitions on every request -- nothing about the
 * browser's copy of the form (types, options, required flags, visibility)
 * is trusted. See docs/FORMS.md §Server-side validation.
 *
 * Image uploads are validated by DETECTED content type (never the client's
 * extension or MIME claim), size, and an actual image decode; stored on the
 * private submissions disk under a random name whose extension comes from
 * the detected type. Only metadata goes into the database. If anything
 * fails after a file was written, the transaction rolls back and every file
 * written for this submission is deleted.
 *
 * Fields marked "unique" (e.g. a student ID) are checked against every
 * earlier submission of the form under the same row lock as the
 * submission limit, so two simultaneous submissions can't both get in.
 */
class FormSubmissionService
{
    /** Request keys that are framework plumbing, not form data. */
    private const RESERVED_INPUT_KEYS = ['_token', '_method', '_embed_page'];

    public function __construct(
        private readonly FormVisibilityResolver $visibility,
        private readonly FormAvailability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $input  request body + uploaded files
     * @param  list<int>  $sessionSubmittedFormIds
     * @param  array<string, mixed>  $metadata
     *
     * @throws ValidationException on invalid input
     * @throws FormUnavailableException when the form can't accept this submission
     */
    public function submit(Form $form, array $input, ?Authenticatable $submitter, array $sessionSubmittedFormIds = [], array $metadata = []): FormSubmission
    {
        $fields = $form->activeFields()->get();
        $input = array_diff_key($input, array_flip(self::RESERVED_INPUT_KEYS));

        $this->rejectUnknownKeys($fields, $input);

        $visible = $this->visibility->resolve($fields, $input);
        $storable = $fields->filter(fn (FormField $f) => $f->type->collectsValue() && $visible[$f->key] && ! $f->isDisabled());
        $validated = $this->validate($storable, $input);

        $written = [];

        try {
            return DB::transaction(function () use ($form, $storable, $validated, $submitter, $sessionSubmittedFormIds, $metadata, &$written) {
                // Re-checked under a row lock so concurrent submissions can't
                // overshoot the submission limit.
                $locked = Form::query()->lockForUpdate()->findOrFail($form->id);
                $state = $this->availability->check($locked, $submitter, $sessionSubmittedFormIds);
                if ($state !== FormAvailabilityState::Open) {
                    throw new FormUnavailableException($state);
                }
                $this->assertUnique($locked, $storable, $validated);

                $submission = $locked->submissions()->create([
                    'submitted_by' => $submitter?->getAuthIdentifier(),
                    'submitted_at' => now(),
                    'form_version' => $locked->lock_version,
                    'metadata' => $metadata ?: null,
                ]);

                foreach ($storable as $field) {
                    if ($field->type->isFileUpload()) {
                        $this->storeImage($submission, $field, $validated[$field->key] ?? null, $written);

                        continue;
                    }

                    $value = $field->usesServerValue() ? $this->serverValue($field) : $this->cleanValue($field, $validated[$field->key] ?? null);

                    $submission->values()->create([
                        'form_field_id' => $field->id,
                        'field_key' => $field->key,
                        'field_label_snapshot' => $field->label,
                        'field_type_snapshot' => $field->type->value,
                        'value' => $value,
                        'display_value' => $this->displayValue($field, $value),
                    ]);
                }

                return $submission;
            });
        } catch (Throwable $e) {
            foreach ($written as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Fields an admin can correct on a stored submission: active,
     * value-collecting fields the visitor answered themselves. Image uploads,
     * hidden/read-only (server-set) and disabled fields are shown, not edited.
     *
     * @return Collection<int, FormField>
     */
    public function editableFields(Form $form): Collection
    {
        return $form->activeFields()->get()
            ->filter(fn (FormField $f) => $f->type->acceptsUserInput() && ! $f->type->isFileUpload() && ! $f->usesServerValue() && ! $f->isDisabled())
            ->values();
    }

    /**
     * An admin's correction of a stored submission. Values are validated with
     * the public form's rules (every answer optional, since conditions and
     * required flags were applied when the visitor submitted) and the unique
     * check skips this submission itself. Each edit is recorded in the
     * submission's metadata (`edits`: who, when, which fields).
     *
     * @param  array<string, mixed>  $input  field key => new value
     * @return list<string> keys of the fields whose value changed
     *
     * @throws ValidationException
     */
    public function update(FormSubmission $submission, array $input, Authenticatable $editor): array
    {
        $form = $submission->form;
        $fields = $this->editableFields($form);
        $input = array_intersect_key($input, array_flip($fields->pluck('key')->all()));
        $validated = $this->validate($fields, $input, adminEdit: true);

        return DB::transaction(function () use ($form, $submission, $fields, $validated, $editor) {
            $locked = Form::query()->lockForUpdate()->findOrFail($form->id);
            $values = [];
            foreach ($fields as $field) {
                $values[$field->key] = $this->cleanValue($field, $validated[$field->key] ?? null);
            }
            $this->assertUnique($locked, $fields, $values, $submission->id);

            $existing = $submission->values()->get()->keyBy('field_key');
            $changed = [];
            foreach ($fields as $field) {
                $value = $values[$field->key];
                $row = $existing->get($field->key);
                if ($row === null) {
                    if ($value === null || $value === [] || $value === false) {
                        continue; // still unanswered
                    }
                    $submission->values()->create([
                        'form_field_id' => $field->id,
                        'field_key' => $field->key,
                        'field_label_snapshot' => $field->label,
                        'field_type_snapshot' => $field->type->value,
                        'value' => $value,
                        'display_value' => $this->displayValue($field, $value),
                    ]);
                    $changed[] = $field->key;

                    continue;
                }
                if ($row->value == $value) {
                    continue;
                }
                $row->update(['value' => $value, 'display_value' => $this->displayValue($field, $value)]);
                $changed[] = $field->key;
            }

            if ($changed !== []) {
                $metadata = $submission->metadata ?? [];
                $metadata['edits'][] = ['by' => $editor->getAuthIdentifier(), 'at' => now()->toIso8601String(), 'fields' => $changed];
                $submission->update(['metadata' => $metadata]);
            }

            return $changed;
        });
    }

    /** Deletes a submission for good: its values, file records and stored files. */
    public function delete(FormSubmission $submission): void
    {
        $files = $submission->files()->get(['disk', 'path']);
        // Explicit, not left to ON DELETE CASCADE (not enforced by every
        // database/connection, e.g. SQLite without foreign keys).
        DB::transaction(function () use ($submission) {
            $submission->values()->delete();
            $submission->files()->delete();
            $submission->delete();
        });
        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }

    /**
     * Refuses a value that another submission of this form already used in a
     * field marked "unique". Surrounding spaces and letter case are ignored
     * ("AB12" = " ab12 "); empty answers are never checked. Call it inside a
     * transaction that holds the form's row lock.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $values  field key => submitted value
     *
     * @throws ValidationException naming each field whose value is taken
     */
    public function assertUnique(Form $form, Collection $fields, array $values, ?int $exceptSubmissionId = null): void
    {
        $errors = [];

        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if (! $field->setting('unique') || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $taken = FormSubmissionValue::query()
                ->where('field_key', $field->key)
                ->whereRaw('LOWER(TRIM(display_value)) = ?', [self::uniqueComparable((string) $value)])
                ->whereHas('submission', fn ($q) => $q->where('form_id', $form->id))
                ->when($exceptSubmissionId !== null, fn ($q) => $q->where('form_submission_id', '!=', $exceptSubmissionId))
                ->exists();

            if ($taken) {
                $errors[$field->key] = "This {$field->label} has already been used to submit this form.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function uniqueComparable(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** @param  list<array{0: string, 1: string}>  $written  (disk, path) of stored files, for cleanup */
    private function storeImage(FormSubmission $submission, FormField $field, mixed $upload, array &$written): void
    {
        $file = null;

        if ($upload instanceof UploadedFile) {
            $mime = (string) $upload->getMimeType(); // detected from the content, not the client's claim
            $extension = (string) array_search($mime, FieldSettingsSchema::IMAGE_TYPES, true);
            $dimensions = @getimagesize($upload->getRealPath()) ?: [null, null];
            $disk = (string) config('formbuilder.storage.submissions_disk', 'local');
            $directory = trim((string) config('formbuilder.storage.submissions_path', 'form-builder/submissions'), '/')
                .'/'.$submission->form_id.'/'.$submission->id;

            $path = Storage::disk($disk)->putFileAs($directory, $upload, Str::uuid()->toString().'.'.$extension);
            if ($path === false) {
                throw new RuntimeException('Could not store the uploaded image.');
            }
            $written[] = [$disk, $path];

            $file = $submission->files()->create([
                'form_field_id' => $field->id,
                'field_key' => $field->key,
                'disk' => $disk,
                'path' => $path,
                'original_name' => self::safeOriginalName($upload->getClientOriginalName(), $extension),
                'mime_type' => $mime,
                'size' => (int) $upload->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
            ]);
        }

        $submission->values()->create([
            'form_field_id' => $field->id,
            'field_key' => $field->key,
            'field_label_snapshot' => $field->label,
            'field_type_snapshot' => $field->type->value,
            'value' => $file ? ['file_id' => $file->id] : null,
            'display_value' => $file?->original_name,
        ]);
    }

    /** Display-only copy of the client's filename: no directories, no odd characters, real extension. */
    public static function safeOriginalName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace(chr(92), '/', $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $base), ' ._');

        return Str::limit($base !== '' ? $base : 'image', 150, '').'.'.$extension;
    }

    /** @param  Collection<int, FormField>  $fields */
    private function rejectUnknownKeys(Collection $fields, array $input): void
    {
        $allowed = $fields->filter(fn (FormField $f) => $f->type->collectsValue())->pluck('key')->all();
        $unknown = array_diff(array_keys($input), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'form' => 'The submission contained fields this form does not have: '.implode(', ', array_slice(array_map('strval', $unknown), 0, 5)).'.',
            ]);
        }
    }

    /**
     * @param  Collection<int, FormField>  $fields  visible, enabled, value-collecting fields
     * @return array<string, mixed>
     */
    private function validate(Collection $fields, array $input, bool $adminEdit = false): array
    {
        $rules = [];
        $messages = [];
        $attributes = [];

        foreach ($fields as $field) {
            if ($field->usesServerValue()) {
                continue; // value comes from the definition, whatever was posted
            }
            $key = $field->key;
            $attributes[$key] = $field->label;
            $attributes["{$key}.*"] = $field->label;
            $rules += $this->rulesFor($field, $adminEdit);

            if ($field->setting('pattern') && $field->setting('pattern_message')) {
                $messages["{$key}.regex"] = $field->setting('pattern_message');
            }
            if ($field->type->isFileUpload()) {
                $types = strtoupper(implode(', ', array_keys(array_intersect(FieldSettingsSchema::IMAGE_TYPES, FieldSettingsSchema::imageMimes($field->settings ?? [])))));
                $messages["{$key}.mimetypes"] = "The :attribute must be an image of type: {$types}.";
                $messages["{$key}.file"] = 'The :attribute must be an uploaded image.';
            }
        }

        return Validator::make($input, $rules, $messages, $attributes)->validate();
    }

    /** @return array<string, list<mixed>> */
    private function rulesFor(FormField $field, bool $adminEdit = false): array
    {
        $key = $field->key;
        $required = $field->required && ! $adminEdit;
        $presence = $required ? 'required' : 'nullable';
        $min = $field->setting('min');
        $max = $field->setting('max');
        $optionValues = array_map(fn ($o) => (string) $o['value'], $field->options());

        $rules = match ($field->type) {
            FormFieldType::Text => array_filter([$presence, 'string', 'max:'.($field->setting('max_length') ?? 1000),
                $field->setting('pattern') ? 'regex:'.FormDefinitionValidator::patternRegex($field->setting('pattern')) : null]),
            FormFieldType::LongText => [$presence, 'string', 'max:'.($field->setting('max_length') ?? 10000)],
            FormFieldType::Email => [$presence, 'string', 'max:255', 'email:rfc'],
            FormFieldType::Number => array_filter([$presence, 'numeric',
                $min !== null ? 'min:'.$min : null, $max !== null ? 'max:'.$max : null], fn ($r) => $r !== null),
            FormFieldType::Phone => [$presence, 'string', 'max:30', 'regex:/^[0-9+()\-.\s]{3,30}$/'],
            FormFieldType::Date, FormFieldType::Time, FormFieldType::DateTime => array_filter([$presence, 'date_format:'.$field->type->dateFormat(),
                $min !== null ? 'after_or_equal:'.$min : null, $max !== null ? 'before_or_equal:'.$max : null]),
            FormFieldType::Select, FormFieldType::Radio => [$presence, 'string', Rule::in($optionValues)],
            FormFieldType::CheckboxGroup => array_filter([$presence, 'array', $required ? 'min:1' : null, 'max:'.count($optionValues)]),
            FormFieldType::Checkbox => $required ? ['accepted'] : ['nullable', Rule::in(['1', 'on', 'true', 'yes'])],
            FormFieldType::Image => [$presence, 'file',
                'mimetypes:'.implode(',', FieldSettingsSchema::imageMimes($field->settings ?? [])),
                'max:'.FieldSettingsSchema::imageMaxKb($field->settings ?? []),
                // Must actually decode as a raster image -- rejects renamed scripts/HTML/SVG
                // even if a content sniff were ever fooled.
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value instanceof UploadedFile && @getimagesize($value->getRealPath()) === false) {
                        $fail('The :attribute must be a valid image.');
                    }
                }],
            default => [],
        };

        $result = [$key => array_values($rules)];
        if ($field->type === FormFieldType::CheckboxGroup) {
            $result["{$key}.*"] = ['string', 'distinct', Rule::in($optionValues)];
        }

        return $result;
    }

    private function serverValue(FormField $field): mixed
    {
        $default = $field->setting('default_value');

        return $field->type === FormFieldType::Checkbox ? (bool) $default : $default;
    }

    private function cleanValue(FormField $field, mixed $value): mixed
    {
        return match (true) {
            $field->type === FormFieldType::Checkbox => $value !== null && $value !== '',
            $field->type === FormFieldType::CheckboxGroup => array_values(array_map('strval', (array) ($value ?? []))),
            is_string($value) => trim($value) === '' ? null : $value,
            default => $value === null ? null : (string) $value,
        };
    }

    /** Human-readable snapshot: option LABELS, Yes/No, joined lists. */
    private function displayValue(FormField $field, mixed $value): ?string
    {
        if ($field->type === FormFieldType::Checkbox) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || $value === []) {
            return null;
        }

        $labels = $field->optionLabels();

        if ($field->type === FormFieldType::CheckboxGroup) {
            return implode(', ', array_map(fn ($v) => $labels[$v] ?? $v, $value));
        }
        if ($field->type->hasOptions()) {
            return $labels[(string) $value] ?? (string) $value;
        }

        return (string) $value;
    }
}
