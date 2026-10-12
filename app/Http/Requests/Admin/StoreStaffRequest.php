<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the `admin` middleware has already decided
    }

    public function rules(): array
    {
        return [
            'firstName'     => ['required', 'string', 'max:60'],
            'middleInitial' => ['nullable', 'string', 'max:1'],
            'lastName'      => ['required', 'string', 'max:60'],
            'email'         => ['required', 'email', 'max:120', 'unique:users,email'],
            'phoneNumber'   => ['nullable', 'string', 'max:20'],
            'role'          => ['required', Rule::in(['admin', 'librarian', 'assistant'])],
        ];
    }
}
