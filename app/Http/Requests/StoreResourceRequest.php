<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'resourceType.exists' => 'Choose one of the types on the list.',
            'name.unique'         => 'A resource with this name already exists.',
        ];
    }

    public function rules(): array
    {
        return [
            // A type is picked from the list (Resources > Types), not typed.
            'resourceType' => ['required', 'string', 'max:100', Rule::exists('resource_types', 'name')],
            'name'         => ['required', 'string', 'max:150', Rule::unique('resources', 'name')],
        ];
    }
}
