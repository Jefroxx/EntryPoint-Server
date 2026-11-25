<?php

namespace App\Services;

use App\Models\FundSource;
use Illuminate\Support\Str;

/**
 * Keeps "Source of fund" tidy. A librarian can type anything into the box, so what is typed is matched to the list
 * first: "Donation", "donated" and "Donated by the class of 2025" are all the same "Donated"; "library budget " is the
 * existing "Library budget". Only a source nobody has used before is added to the list, so it is there next time.
 */
class FundSourceService
{
    /** Any wording that means "it was given to the library" (donation, donated, donor...). */
    public static function isDonated(?string $source): bool
    {
        return (bool) preg_match('/donat|donor/i', (string) $source);
    }

    /**
     * The name to store for what was typed: an existing source if it is the same thing, otherwise a new one (added to
     * the list). Null when nothing was typed.
     */
    public function resolve(?string $typed): ?string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $typed));

        if ($name === '') {
            return null;
        }

        if (self::isDonated($name)) {
            return $this->named('Donated');
        }

        $key = $this->key($name);
        foreach (FundSource::all() as $source) {
            if ($this->key($source->name) === $key) {
                return $source->name;
            }
        }

        return $this->named(mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1));
    }

    /** The list entry with this name, made if it isn't there yet. */
    private function named(string $name): string
    {
        $source = FundSource::firstOrCreate(
            ['name' => $name],
            ['uuid' => (string) Str::uuid(), 'sortOrder' => (int) FundSource::max('sortOrder') + 1]
        );

        return $source->name;
    }

    /** Case, spacing, punctuation and a plural "s" don't make a different source. */
    private function key(string $name): string
    {
        return rtrim(preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)), 's');
    }
}
