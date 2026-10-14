<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $librarian = $this->route('librarian');

        return [
            'firstName'     => ['sometimes', 'required', 'string', 'max:60'],
            'middleInitial' => ['nullable', 'string', 'max:1'],
            'lastName'      => ['sometimes', 'required', 'string', 'max:60'],
            'email'         => ['sometimes', 'required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($librarian->librarianID, 'userID')],
            'phoneNumber'   => ['nullable', 'string', 'max:20'],
            'role'          => ['sometimes', 'required', Rule::in(['admin', 'librarian', 'assistant'])],
        ];
    }
}
