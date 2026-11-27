<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFineRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The area comes from the URL; it has to be one of the library's areas (Library > Areas & Funds). */
    protected function prepareForValidation(): void
    {
        $this->merge(['area' => $this->route('area')]);
    }

    public function rules(): array
    {
        return [
            'area'            => ['required', 'string', 'exists:loan_periods,area'],
            'rate'            => ['required', 'numeric', 'min:0', 'max:10000', 'decimal:0,2'],
            'rateUnit'        => ['required', 'in:day,hour'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'area.exists'     => 'That is not one of the library\'s areas.',
            'rate.decimal'    => 'Use at most two decimal places, like 2.50.',
            'rate.max'        => 'Enter a rate of 10,000 or less.',
        ];
    }
}
