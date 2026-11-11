<?php

namespace App\Rules;

use App\Services\BookMetadataService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A real ISBN: 10 or 13 digits (an ISBN-10 may end in X), hyphens and spaces allowed, and the last digit is a check
 * digit, so a mistyped number is caught instead of being saved as a book nobody can look up.
 */
class ValidIsbn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (BookMetadataService::normalizeIsbn(is_string($value) ? $value : null) === null) {
            $fail("That isn't a valid ISBN. It has 10 or 13 digits (an ISBN-10 may end in X), and its last digit is a check digit, so a typo is usually caught.");
        }
    }
}
