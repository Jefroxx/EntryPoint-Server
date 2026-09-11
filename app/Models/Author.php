<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Author extends Model
{
    use HasFactory;

    protected $primaryKey = 'authorID';

    protected $fillable = ['uuid', 'name'];

    /**
     * A simplified stand-in for a Cutter-Sanborn table: maps a letter to a
     * single digit so generated author numbers stay short and roughly
     * alphabetical. This is NOT the official Cutter-Sanborn table (that's
     * licensed/copyrighted) — just a deterministic approximation that's
     * good enough for shelving order in this system.
     */
    private const CUTTER_TABLE = [
        'a' => 1, 'b' => 2, 'c' => 3, 'd' => 3, 'e' => 4, 'f' => 4, 'g' => 5,
        'h' => 5, 'i' => 6, 'j' => 6, 'k' => 6, 'l' => 7, 'm' => 7, 'n' => 7,
        'o' => 8, 'p' => 8, 'q' => 8, 'r' => 8, 's' => 8, 't' => 9, 'u' => 9,
        'v' => 9, 'w' => 9, 'x' => 9, 'y' => 9, 'z' => 9,
    ];

    protected static function booted(): void
    {
        static::creating(function (Author $author) {
            if (empty($author->authorNumber)) {
                $author->authorNumber = self::generateAuthorNumber($author->name);
            }
        });
    }

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_author', 'authorID', 'bookID')
            ->withPivot('role');
    }

    /**
     * Generate a Cutter-style author number, e.g. "Cormen" -> "C887".
     * The letter comes from the first letter of the surname; the digits are
     * derived from the remaining letters. Collisions (two authors landing on
     * the same code) are resolved by bumping the digits.
     */
    public static function generateAuthorNumber(string $authorName): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', self::surnameOf($authorName));
        $letters = $letters !== '' ? $letters : 'X';

        $firstLetter = strtoupper($letters[0]);
        $digits = self::cutterDigits(substr($letters, 1));

        $candidate = $firstLetter . $digits;
        $bump = 0;

        while (self::where('authorNumber', $candidate)->exists()) {
            $bump++;

            if ($bump > 998) {
                // Astronomically unlikely, but never loop forever.
                $candidate = $firstLetter . $digits . strtoupper(Str::random(2));
                break;
            }

            $bumped = ((int) $digits + $bump) % (10 ** strlen($digits));
            $candidate = $firstLetter . str_pad((string) $bumped, strlen($digits), '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /**
     * Surname (last word) of a full name, e.g. "Thomas H. Cormen" -> "Cormen".
     * Public so callers matching a verified cutter number (from a live
     * lookup) against an author use the same surname extraction we do here.
     */
    public static function surnameOf(string $fullName): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: [], fn ($p) => $p !== ''));

        return $parts ? end($parts) : $fullName;
    }

    private static function cutterDigits(string $rest): string
    {
        $digits = '';

        foreach (str_split(strtolower($rest)) as $char) {
            if (isset(self::CUTTER_TABLE[$char])) {
                $digits .= self::CUTTER_TABLE[$char];

                if (strlen($digits) === 3) {
                    break;
                }
            }
        }

        return str_pad($digits !== '' ? $digits : '3', 2, '0');
    }
}
