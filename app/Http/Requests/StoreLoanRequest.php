<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'studentID'     => ['required', 'integer', 'exists:students,studentID'],
            'copyID'        => ['required', 'integer', 'exists:book_copies,copyID'],
            'reservationID' => ['nullable', 'integer', 'exists:reservations,reservationID'],
        ];
    }
}
