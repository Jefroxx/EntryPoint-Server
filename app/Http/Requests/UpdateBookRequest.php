<?php

namespace App\Http\Requests;

use App\Rules\ValidIsbn;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'publicationYear.max' => "The publication year can't be in the future. It can be " . now()->year . ' at the latest.',
            'publicationYear.min' => 'Enter the four-digit year the book was published, like 2024.',
        ];
    }

    /**
     * An ISBN is checked when it is changed. A book catalogued long ago may carry one that would not pass (a test
     * entry, an unreadable label), and that must not stop its title or shelf from being edited.
     */
    private function isbnRules(): array
    {
        $rules = ['sometimes', 'required', 'string', 'max:20'];
        $book = $this->route('book');
        $unchanged = is_object($book) && $this->input('isbn') === $book->isbn;

        return $unchanged ? $rules : [...$rules, new ValidIsbn];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'          => ['sometimes', 'string', 'max:255'],
            'deweyNumber'    => ['sometimes', 'nullable', 'string', 'regex:/^\d{3}(\.\d{1,10})?$/'],
            'areaOfLibrary' => ['sometimes', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],
            // Marks the book as a new arrival (or takes the mark off): it then shows on the student Home.
            'isNewArrival'   => ['sometimes', 'boolean'],

            'isbn'             => $this->isbnRules(),
            // A book can't have been published in the future.
            'publicationYear'  => ['sometimes', 'required', 'integer', 'min:1000', 'max:' . now()->year],
            'volume'         => ['sometimes', 'required', 'string', 'max:50'],
            'edition'        => ['sometimes', 'required', 'string', 'max:50'],
            'pageCount'      => ['sometimes', 'required', 'integer', 'min:1'],
            'publisher'      => ['sometimes', 'required', 'string', 'max:255'],
            'sourceOfFund'   => ['sometimes', 'required', 'string', 'max:255'],
            'cost'           => ['sometimes', 'required', 'numeric', 'min:0'],
            'remarks'        => ['nullable', 'string', 'max:1000'],

            'subjectID'      => ['sometimes', 'integer', 'exists:book_subjects,subjectID'],
            'subjectName'    => ['sometimes', 'string', 'max:255'],

            'authors'                 => ['sometimes', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],

        ];
    }
}
