<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $area = $this->route('area');

        return [
            'name'        => ['required', 'string', 'max:80', Rule::unique('loan_periods', 'name')->ignore($area?->area, 'area')],
            'acronym'     => ['required', 'string', 'max:12', Rule::unique('loan_periods', 'acronym')->ignore($area?->area, 'area')],
            'loanable'    => ['required', 'boolean'],
            'periodValue' => ['exclude_if:loanable,false,0', 'required', 'integer', 'min:1', 'max:365'],
            'periodUnit'  => ['exclude_if:loanable,false,0', 'required', 'in:days,hours,overnight'],
        ];
    }
}
