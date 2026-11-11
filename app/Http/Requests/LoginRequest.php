<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The librarian sign-in also takes a username (a student assistant's account has no email); the student
        // sign-in is email only.
        $staffSignIn = $this->is('api/librarian/login');

        return [
            'email'    => $staffSignIn ? ['required', 'string', 'max:255'] : ['required', 'email'],
            // A cap, so a megabyte "password" can't be sent just to make the server spend time hashing it.
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
