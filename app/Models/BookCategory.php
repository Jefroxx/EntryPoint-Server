<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCategory extends Model
{
    use HasFactory;

    protected $primaryKey = 'categoryID';

    protected $fillable = ['uuid', 'name'];

    protected static function booted(): void
    {
        static::creating(function (BookCategory $category) {
            if (empty($category->classificationCode)) {
                $category->classificationCode = self::classifyByName($category->name);
            }
        });
    }

    public function books()
    {
        return $this->hasMany(Book::class, 'categoryID', 'categoryID');
    }

    /**
     * Maps a category name to a Dewey Decimal classification code using the
     * table in config/classification.php (the standard, widely published
     * top-level/second-level Dewey breakdown — not the detailed, licensed
     * WebDewey schedules). First keyword match wins; falls back to the
     * configured default ("000") otherwise.
     *
     * This is only the *fallback* source — BookController prefers a live
     * Open Library lookup (see LibraryClassificationService) when the book
     * being added has an ISBN, since that's an authoritative, already
     * catalogued Dewey number rather than a keyword guess.
     */
    public static function classifyByName(string $name): string
    {
        $needle = strtolower($name);
        $code = config('classification.dewey.default', '000');

        foreach (config('classification.dewey.map', []) as $keyword => $deweyCode) {
            if (str_contains($needle, $keyword)) {
                $code = $deweyCode;
                break;
            }
        }

        return self::uniqueCode($code);
    }

    /**
     * Appends a/b/c... if another category already landed on the exact same
     * code (e.g. two category names matching the same keyword), so every
     * category still gets a distinct classificationCode.
     */
    private static function uniqueCode(string $code): string
    {
        $candidate = $code;
        $suffix = 0;

        while (self::where('classificationCode', $candidate)->exists()) {
            $candidate = $code . chr(97 + $suffix);
            $suffix++;
        }

        return $candidate;
    }
}
