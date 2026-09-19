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

    public function rules(): array
    {
        return [
            'resourceType' => ['sometimes', 'string', 'max:100'],
            'name'         => ['sometimes', 'string', 'max:150'],
            // 'In Use' is set only by starting/ending a usage session, not by a direct edit.
            'status'       => ['sometimes', Rule::in(['Available', 'Unavailable'])],
        ];
    }
}
