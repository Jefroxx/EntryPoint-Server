<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'borrowed' is set by checking a copy out, never by hand; 'retired' removes the copy.
            // A request changes the status, this copy's own record (where it came from, what it cost, remarks), or both.
            // (hasAny looks at whether the key was sent, so clearing remarks with null still counts as an edit.)
            'status'       => [Rule::requiredIf(fn () => ! $this->hasAny(['sourceOfFund', 'cost', 'remarks'])), 'in:available,damaged,lost,retired'],
            'sourceOfFund' => ['sometimes', 'required', 'string', 'max:255'],
            'cost'         => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'remarks'      => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
