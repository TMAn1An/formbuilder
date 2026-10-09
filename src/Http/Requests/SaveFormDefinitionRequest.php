<?php

namespace TMAn1An\FormBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Services\Forms\FormDefinitionValidator;

/**
 * The builder's JSON save (Save draft / Publish / autosave). Rules come
 * from FormDefinitionValidator, built per field type from the payload
 * itself; cross-field checks (unique keys, field ownership, condition
 * references, ...) run in its after() hook. The custom-code keys are
 * `prohibited` for anyone the host's Authorizer doesn't allow.
 */
class SaveFormDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', $this->targetForm());
    }

    public function rules(): array
    {
        return app(FormDefinitionValidator::class)->rules($this->targetForm(), $this->input('fields'), $this->canManageCustomCode());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(FormDefinitionValidator::class)->after($v, $this->targetForm(), $this->all()));
    }

    public function messages(): array
    {
        return [
            'custom_css.prohibited' => 'Only an administrator with custom-code access can change custom code.',
            'custom_html_before.prohibited' => 'Only an administrator with custom-code access can change custom code.',
            'custom_html_after.prohibited' => 'Only an administrator with custom-code access can change custom code.',
            'custom_js.prohibited' => 'Only an administrator with custom-code access can change custom code.',
            'fields.*.key.regex' => 'Keys must start with a lowercase letter and use only a-z, 0-9 and _ (max 64 characters).',
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }

    public function targetForm(): Form
    {
        return $this->route('form');
    }

    public function canManageCustomCode(): bool
    {
        return Gate::forUser($this->user())->allows('manageCustomCode', Form::class);
    }
}
