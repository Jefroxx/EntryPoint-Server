<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $student = $this->route('student');

        return [
            'firstName'        => ['sometimes', 'required', 'string', 'max:60'],
            'middleInitial'    => ['nullable', 'string', 'max:1'],
            'lastName'         => ['sometimes', 'required', 'string', 'max:60'],
            'email'            => ['sometimes', 'required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($student->studentID, 'userID')],
            'phoneNumber'      => ['nullable', 'string', 'max:20'],
            'memberType'       => ['sometimes', 'required', Rule::in(['faculty', 'staff'])],
            'employeeIDNumber' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('students', 'studentIDNumber')->ignore($student->studentID, 'studentID')],
            'department'       => ['sometimes', 'required', 'string', 'max:80'],
        ];
    }
}
