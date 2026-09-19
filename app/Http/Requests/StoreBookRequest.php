<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            // Nullable: if omitted, BookController auto-generates it from the
            // subject's Dewey classification + the primary author's cutter
            // number (optionally refined by a live ISBN lookup).
            'classNumber'    => ['nullable', 'string', 'max:100'],
            // Alias for classNumber (the Nuxt client's naming).
            'callNumber'     => ['nullable', 'string', 'max:100'],
            'areasOfLibrary' => ['nullable', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],
            'quantity'       => ['required', 'integer', 'min:1', 'max:100'],

            'isbn'             => ['nullable', 'string', 'max:20'],
            'publicationYear'  => ['nullable', 'integer', 'min:1000', 'max:9999'],
            'volume'         => ['nullable', 'string', 'max:50'],
            'edition'        => ['nullable', 'string', 'max:50'],
            'pages'          => ['nullable', 'integer', 'min:1'],
            'publisher'      => ['nullable', 'string', 'max:255'],
            'sourceOfFund'   => ['nullable', 'string', 'max:255'],
            'cost'           => ['nullable', 'numeric', 'min:0'],
            'copyNumber'     => ['nullable', 'string', 'max:50'],
            'remarks'        => ['nullable', 'string', 'max:1000'],

            'subjectID'      => ['nullable', 'integer', 'exists:book_subjects,subjectID', 'required_without:subjectName'],
            'subjectName'    => ['nullable', 'string', 'max:255', 'required_without:subjectID'],

            'authors'                 => ['required', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],
        ];
    }
}
