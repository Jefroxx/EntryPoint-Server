<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'borrowed' is set by checking a copy out, never by hand; 'retired' removes the copy.
            'status' => ['required', 'in:available,damaged,lost,retired'],
        ];
    }
}
