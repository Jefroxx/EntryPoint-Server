<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'        => ['nullable', 'string', 'max:255'],
            'subjectID'     => ['nullable', 'integer', 'exists:book_subjects,subjectID'],
            // An area of the library (loan_periods.area): what Discover and Home filter by.
            'area'          => ['nullable', 'string', 'exists:loan_periods,area'],
            'availableOnly' => ['nullable', 'boolean'],
            // Only the books the library marked as new arrivals, newest first.
            'newArrivalsOnly' => ['nullable', 'boolean'],
            'perPage'       => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
}
