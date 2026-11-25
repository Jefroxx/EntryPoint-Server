<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFundSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $source = $this->route('fundSource');

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('fund_sources', 'name')->ignore($source?->fundSourceID, 'fundSourceID')],
        ];
    }
}
