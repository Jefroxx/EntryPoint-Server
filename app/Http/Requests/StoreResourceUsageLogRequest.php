<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceUsageLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resID'     => ['required', 'integer', 'exists:resources,resID'],
            'studentID' => ['required', 'integer', 'exists:students,studentID'],
        ];
    }
}
