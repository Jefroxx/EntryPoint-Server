<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    use HasFactory;

    protected $primaryKey = 'authorID';

    protected $fillable = ['uuid', 'name', 'cutterNumber'];

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_author', 'authorID', 'bookID')
            ->withPivot('role');
    }

    public static function generateCutter(string $name): string
    {
        $surname = self::extractSurname($name);
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $surname));

        if ($letters === '') {
            throw new \InvalidArgumentException('Cannot generate a cutter number from an empty surname.');
        }

        $firstLetter = $letters[0];
        $isQu = str_starts_with($letters, 'QU');

        $nextIndex = $isQu ? 3 : 2;
        $cutter = $firstLetter . self::baseDigit($letters, $firstLetter, $isQu);

        while (self::where('cutterNumber', $cutter)->exists()) {
            $nextLetter = $letters[$nextIndex] ?? null;

            if ($nextLetter === null) {
                $suffix = 1;
                $candidate = $cutter . $suffix;
                while (self::where('cutterNumber', $candidate)->exists()) {
                    $suffix++;
                    $candidate = $cutter . $suffix;
                }
                return $candidate;
            }

            $cutter .= self::expansionDigit($nextLetter);
            $nextIndex++;
        }

        return $cutter;
    }

    private static function extractSurname(string $name): string
    {
        if (str_contains($name, ',')) {
            return trim(explode(',', $name)[0]);
        }

        $parts = preg_split('/\s+/', trim($name));
        return end($parts);
    }

    private static function baseDigit(string $letters, string $firstLetter, bool $isQu): int
    {
        if ($isQu) {
            return self::lookup($letters[2] ?? '', [
                'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
                'R-S' => 7, 'T-X' => 8, 'Y-Z' => 9,
            ]);
        }

        $second = $letters[1] ?? '';

        if (in_array($firstLetter, ['A', 'E', 'I', 'O', 'U'])) {
            return self::lookup($second, [
                'B-C' => 2, 'D-K' => 3, 'L-M' => 4, 'N-O' => 5,
                'P-Q' => 6, 'R-R' => 7, 'S-T' => 8, 'U-Y' => 9,
            ]);
        }

        if ($firstLetter === 'S') {
            return self::lookup($second, [
                'A-C' => 2, 'D-D' => 3, 'E-G' => 4, 'H-L' => 5,
                'M-S' => 6, 'T-T' => 7, 'U-V' => 8, 'W-Z' => 9,
            ]);
        }

        return self::lookup($second, [
            'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
            'R-T' => 7, 'U-X' => 8, 'Y-Z' => 9,
        ]);
    }

    private static function expansionDigit(string $letter): int
    {
        return self::lookup($letter, [
            'A-D' => 3, 'E-H' => 4, 'I-L' => 5, 'M-O' => 6,
            'P-S' => 7, 'T-V' => 8, 'W-Z' => 9,
        ]);
    }

    private static function lookup(string $letter, array $ranges): int
    {
        if ($letter === '') {
            return min($ranges);
        }

        foreach ($ranges as $range => $digit) {
            [$start, $end] = explode('-', $range);
            if ($letter >= $start && $letter <= $end) {
                return $digit;
            }
        }

        return end($ranges);
    }
}
