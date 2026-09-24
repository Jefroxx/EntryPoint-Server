<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $domain = config('portal.student_email_domain');
        $email = ['required', 'email', 'unique:users,email'];

        if ($domain) {
            $email[] = 'ends_with:@' . $domain;
        }

        return [
            'firstName'       => ['required', 'string', 'max:255'],
            'middleInitial'   => ['nullable', 'string', 'max:5'],
            'lastName'        => ['required', 'string', 'max:255'],
            'email'           => $email,
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
            'phoneNumber'     => ['nullable', 'string', 'max:20'],
            'birthDate'       => ['nullable', 'date'],
            'address'         => ['nullable', 'string', 'max:255'],
            'studentIDNumber' => ['required', 'string', 'max:50', 'unique:students,studentIDNumber'],
            'academicProgram' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.ends_with' => 'Use your school email (ending in @' . config('portal.student_email_domain') . ').',
        ];
    }
}
