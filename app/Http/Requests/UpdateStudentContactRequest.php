<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The details a student may change on their own. Name, student ID, program and email were
 * checked by a librarian when the account was approved, so those stay with the library.
 */
class UpdateStudentContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            'address'     => ['nullable', 'string', 'max:255'],
            'birthDate'   => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
