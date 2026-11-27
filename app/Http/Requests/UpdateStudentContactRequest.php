<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The details a student may change on their own, including their ID number. Name, program and email were
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
            'memberIDNumber' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('students', 'memberIDNumber')->ignore($this->user()->student->studentID, 'studentID')],
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            'address'     => ['nullable', 'string', 'max:255'],
            'birthDate'   => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
