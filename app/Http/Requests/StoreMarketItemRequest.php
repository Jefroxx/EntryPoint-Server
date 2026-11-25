<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['type.exists' => 'Choose one of the types on the list.'];
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            // A type is picked from the list (Rewards Market > Types), not typed.
            'type'      => ['nullable', 'string', 'max:100', \Illuminate\Validation\Rule::exists('market_item_types', 'name')],
            'pointCost' => ['required', 'integer', 'min:0'],
            'stock'     => ['required', 'integer', 'min:0'],
        ];
    }
}
