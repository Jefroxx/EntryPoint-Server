<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by 'librarian' middleware at the route level
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'callNumber'     => ['required', 'string', 'max:100'],
            'coverImageURL'  => ['nullable', 'url', 'max:255'],
            'shelfLocation'  => ['nullable', 'string', 'max:100'],
            'quantity'       => ['required', 'integer', 'min:1', 'max:100'],

            // Category: pick existing OR create new — exactly one must be provided
            'categoryID'     => ['nullable', 'integer', 'exists:book_categories,categoryID', 'required_without:categoryName'],
            'categoryName'   => ['nullable', 'string', 'max:255', 'required_without:categoryID'],

            // Authors: array of items, each either an existing authorID or a new name
            'authors'                 => ['required', 'array', 'min:1'],
            'authors.*.authorID'      => ['nullable', 'integer', 'exists:authors,authorID', 'required_without:authors.*.name'],
            'authors.*.name'          => ['nullable', 'string', 'max:255', 'required_without:authors.*.authorID'],
            'authors.*.role'          => ['nullable', 'string', 'max:100'],
        ];
    }
}
