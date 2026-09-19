<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFineRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate'            => ['required', 'numeric', 'min:0'],
            'rateUnit'        => ['required', 'in:day,hour'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:30'],
        ];
    }
}
