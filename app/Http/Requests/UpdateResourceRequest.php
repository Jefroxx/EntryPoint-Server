<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends FormRequest
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
            'resourceType' => ['sometimes', 'string', 'max:100', Rule::exists('resource_types', 'name')],
            'name'         => ['sometimes', 'string', 'max:150', Rule::unique('resources', 'name')->ignore($this->route('resource')?->resID, 'resID')],
            // 'In Use' is set only by starting/ending a usage session, not by a direct edit.
            'status'       => ['sometimes', Rule::in(['Available', 'Unavailable'])],
        ];
    }
}
