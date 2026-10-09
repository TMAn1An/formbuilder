<?php

namespace TMAn1An\FormBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Services\Pages\PageDefinitionValidator;

/**
 * The Page Builder's JSON save. The ENTIRE definition (structure, block
 * settings, references, permissions) is validated here, before the
 * controller -- and therefore before any database write -- runs.
 */
class SavePageDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', $this->targetPage());
    }

    public function rules(): array
    {
        return app(PageDefinitionValidator::class)->rules($this->targetPage(), $this->input('blocks'), $this->canManageCustomCode());
    }

    public function messages(): array
    {
        return app(PageDefinitionValidator::class)->messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(PageDefinitionValidator::class)->after($v, $this->targetPage(), $this->all()));
    }

    public function targetPage(): Page
    {
        return $this->route('page');
    }

    public function canManageCustomCode(): bool
    {
        return Gate::forUser($this->user())->allows('manageCustomCode', Page::class);
    }
}
