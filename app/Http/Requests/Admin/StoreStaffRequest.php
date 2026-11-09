<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A staff account. Two kinds of person get one:
 *  - anyone with an email (librarian, administrator, an outside assistant): name and email are typed in, and the email
 *    is confirmed with a mailed link;
 *  - a student assistant, who must be picked from the registered students (`studentUuid`): the name comes from the
 *    student record, there is no email, and the login is a generated username.
 */
class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the `admin` middleware has already decided
    }

    public function rules(): array
    {
        $fromStudent = $this->input('role') === 'assistant' && $this->input('assistantSource') === 'student';

        return [
            'role'            => ['required', Rule::in(['admin', 'librarian', 'assistant'])],
            'assistantSource' => ['nullable', Rule::in(['student', 'outside'])],
            // Only a student who exists in the system can be made a student assistant.
            'studentUuid'     => $fromStudent
                ? ['required', 'string', Rule::exists('students', 'uuid')]
                : ['prohibited'],

            'firstName'       => $fromStudent ? ['nullable'] : ['required', 'string', 'max:60'],
            'middleInitial'   => ['nullable', 'string', 'max:1'],
            'lastName'        => $fromStudent ? ['nullable'] : ['required', 'string', 'max:60'],
            'email'           => $fromStudent ? ['nullable'] : ['required', 'email', 'max:120', 'unique:users,email'],
            'phoneNumber'     => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'studentUuid.required'   => 'Choose a registered student.',
            'studentUuid.exists'     => 'Choose a student who is registered in the system.',
            'studentUuid.prohibited' => 'A student can only be picked for a student assistant.',
        ];
    }
}
