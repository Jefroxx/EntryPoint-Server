<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A faculty or staff member made by the administrator. Students register themselves. */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName'        => ['required', 'string', 'max:60'],
            'middleInitial'    => ['nullable', 'string', 'max:1'],
            'lastName'         => ['required', 'string', 'max:60'],
            'email'            => ['required', 'email', 'max:120', 'unique:users,email'],
            'phoneNumber'      => ['nullable', 'string', 'max:20'],
            'memberType'       => ['required', Rule::in(['faculty', 'staff'])],
            'employeeIDNumber' => ['required', 'string', 'max:40', 'unique:students,studentIDNumber'],
            'department'       => ['required', 'string', 'max:80'],
        ];
    }
}
