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
            'classNumber'    => ['sometimes', 'string', 'max:100'],
            'areasOfLibrary' => ['sometimes', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],

            'isbn'           => ['nullable', 'string', 'max:20'],
            'volume'         => ['nullable', 'string', 'max:50'],
            'edition'        => ['nullable', 'string', 'max:50'],
            'pages'          => ['nullable', 'integer', 'min:1'],
            'publisher'      => ['nullable', 'string', 'max:255'],
            'sourceOfFund'   => ['nullable', 'string', 'max:255'],
            'cost'           => ['nullable', 'numeric', 'min:0'],
            'copyNumber'     => ['nullable', 'string', 'max:50'],
            'remarks'        => ['nullable', 'string', 'max:1000'],

            'subjectID'      => ['sometimes', 'integer', 'exists:book_subjects,subjectID'],
            'subjectName'    => ['sometimes', 'string', 'max:255'],

            'authors'                 => ['sometimes', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],

            'quantity'       => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
