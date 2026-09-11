<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'          => ['sometimes', 'string', 'max:255'],
            'callNumber'     => ['sometimes', 'string', 'max:100'],
            'isbn'           => ['sometimes', 'nullable', 'string', 'max:20'],
            'publicationYear' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:' . (date('Y') + 1)],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],

            'categoryID'     => ['sometimes', 'integer', 'exists:book_categories,categoryID'],
            'categoryName'   => ['sometimes', 'string', 'max:255'],

            'authors'                 => ['sometimes', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],

            // Absolute target count of active (non-retired) copies
            'quantity'       => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
