<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string'],
            // Staff accounts can change records and other people's accounts, so theirs must be longer.
            'newPassword'     => ['required', 'string', 'min:'.($this->user()?->userType === 'librarian' ? 10 : 8), 'max:255', 'confirmed'],
        ];
    }
}
