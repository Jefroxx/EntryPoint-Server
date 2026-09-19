<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveLoanPeriodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'circulation' => ['required', 'integer', 'min:1', 'max:365'],
            'reserved'    => ['required', 'integer', 'min:1', 'max:365'],
            'filipiniana' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
