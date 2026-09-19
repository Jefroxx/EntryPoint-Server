<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'itemID'   => ['required', 'integer', 'exists:market_items,itemID'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
