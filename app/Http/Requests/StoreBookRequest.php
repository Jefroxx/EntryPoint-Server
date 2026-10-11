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
            'areaOfLibrary' => ['nullable', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],
            'quantity'       => ['required', 'integer', 'min:1', 'max:100'],

            // A new book's record is complete: only the remarks may be left out.
            // (A book with no edition or volume says "N/A". A donated book costs 0.)
            'isbn'             => ['required', 'string', 'max:20'],
            'publicationYear'  => ['required', 'integer', 'min:1000', 'max:9999'],
            'volume'         => ['required', 'string', 'max:50'],
            'edition'        => ['required', 'string', 'max:50'],
            'pages'          => ['required', 'integer', 'min:1'],
            'publisher'      => ['required', 'string', 'max:255'],
            'sourceOfFund'   => ['required', 'string', 'max:255'],
            'cost'           => ['required', 'numeric', 'min:0'],
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
