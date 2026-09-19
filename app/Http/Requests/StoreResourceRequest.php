<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resourceType' => ['required', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:150'],
        ];
    }
}
