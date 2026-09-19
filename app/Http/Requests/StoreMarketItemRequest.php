<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            'type'      => ['nullable', 'string', 'max:100'],
            'pointCost' => ['required', 'integer', 'min:0'],
            'stock'     => ['required', 'integer', 'min:0'],
        ];
    }
}
