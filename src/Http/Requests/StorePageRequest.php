<?php

namespace TMAn1An\FormBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use TMAn1An\FormBuilder\Models\Page;

/** "Create page" -- a title; blocks are added in the builder. */
class StorePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', Page::class);
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200']];
    }
}
