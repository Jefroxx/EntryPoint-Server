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
            // The Dewey number alone ("005.1"). Left out, it is looked up by ISBN.
            'deweyNumber'    => ['nullable', 'string', 'regex:/^\d{3}(\.\d{1,10})?$/'],
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
            'pageCount'      => ['required', 'integer', 'min:1'],
            'publisher'      => ['required', 'string', 'max:255'],
            'sourceOfFund'   => ['required', 'string', 'max:255'],
            'cost'           => ['required', 'numeric', 'min:0'],
            'remarks'        => ['nullable', 'string', 'max:1000'],
            // Ticked only for books that really just arrived (old books get catalogued too).
            'isNewArrival'   => ['nullable', 'boolean'],

            'subjectID'     => ['nullable', 'integer', 'exists:book_subjects,subjectID', 'required_without:subjectName'],
            'subjectName'    => ['nullable', 'string', 'max:255', 'required_without:subjectID'],

            'authors'                 => ['required', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],
        ];
    }
}
