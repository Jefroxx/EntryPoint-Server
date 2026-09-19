<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookSubject;
use App\Repositories\Contracts\AuthorRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    private const CUTTER_TABLE_FIRST_VOWEL = [
        'B-C' => 2, 'D-K' => 3, 'L-M' => 4, 'N-O' => 5,
        'P-Q' => 6, 'R-R' => 7, 'S-T' => 8, 'U-Y' => 9,
    ];

    private const CUTTER_TABLE_FIRST_S = [
        'A-C' => 2, 'D-D' => 3, 'E-G' => 4, 'H-L' => 5,
        'M-S' => 6, 'T-T' => 7, 'U-V' => 8, 'W-Z' => 9,
    ];

    private const CUTTER_TABLE_DEFAULT = [
        'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
        'R-T' => 7, 'U-X' => 8, 'Y-Z' => 9,
    ];

    private const CUTTER_TABLE_QU = [
        'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
        'R-S' => 7, 'T-X' => 8, 'Y-Z' => 9,
    ];

    private const CUTTER_EXPANSION_TABLE = [
        'A-D' => 3, 'E-H' => 4, 'I-L' => 5, 'M-O' => 6,
        'P-S' => 7, 'T-V' => 8, 'W-Z' => 9,
    ];

    public function __construct(
        private BookRepositoryInterface $books,
        private BookCopyRepositoryInterface $bookCopies,
        private AuthorRepositoryInterface $authors,
        private BookSubjectRepositoryInterface $subjects,
        private LoanRepositoryInterface $loans,
        private LibraryClassificationService $classification,
    ) {
    }

    /**
     * @return array{totalBooks: int, availableBooks: int, borrowedBooks: int, overdueBooks: int}
     */
    public function libraryStats(): array
    {
        $counts = $this->bookCopies->countsByStatus();

        return [
            'totalBooks'     => $this->books->totalCount(),
            'availableBooks' => (int) $counts->get('available', 0),
            'borrowedBooks'  => (int) $counts->get('borrowed', 0),
            'overdueBooks'   => $this->loans->stats()['overdue'],
        ];
    }

    /**
     * Catalog listing for the client's Library page. Field names are
     * remapped to match the frontend's contract (`callNumber` instead of
     * the internal `classNumber`), independent of the storage schema.
     */
    public function catalogIndex(?string $search, int $perPage): LengthAwarePaginator
    {
        return $this->books->paginateCatalog($search, $perPage)->through(fn (Book $book) => [
            'bookID'        => $book->bookID,
            'title'         => $book->title,
            'isbn'          => $book->isbn,
            'callNumber'    => $book->classNumber,
            'coverImageURL' => $book->coverImageURL,
            'subject'       => $book->subject ? [
                'subjectID' => $book->subject->subjectID,
                'name'      => $book->subject->name,
            ] : null,
            'authors' => $book->authors->map(fn (Author $author) => [
                'authorID' => $author->authorID,
                'name'     => $author->name,
            ])->values(),
            'copies' => $book->copies->map(fn ($copy) => [
                'copyID'          => $copy->copyID,
                'accessionNumber' => $copy->accessionNumber,
                'status'          => $copy->status,
            ])->values(),
        ]);
    }

    public function createBook(array $validated): Book
    {
        // Best-effort live lookup, done before the DB transaction opens (a
        // network call has no business holding DB locks). Returns null on
        // any miss/failure/timeout — never blocks the book from being added.
        $lookup = ! empty($validated['isbn'])
            ? $this->classification->lookupByIsbn($validated['isbn'])
            : null;

        $book = DB::transaction(function () use ($validated, $lookup) {
            $subject = ! empty($validated['subjectID'])
                ? $this->subjects->findOrFail($validated['subjectID'])
                : $this->subjects->firstOrCreateByName($validated['subjectName']);

            // Resolve authors first (existing IDs or find-or-create by name)
            // so the primary author's cutter number is known before the
            // class number is built below.
            $authorEntries = [];
            foreach ($validated['authors'] as $authorInput) {
                $author = ! empty($authorInput['authorID'])
                    ? $this->authors->findOrFail($authorInput['authorID'])
                    : $this->authors->firstOrCreateByName($authorInput['name']);

                $authorEntries[] = ['model' => $author, 'role' => $authorInput['role'] ?? null];
            }

            $primaryAuthor = $authorEntries[0]['model'];

            // If the lookup found a real LC classification, prefer whichever
            // candidate's letter matches the primary author's surname.
            $verifiedCutter = $lookup
                ? $this->classification->matchCutterForSurname($lookup['cutterCandidates'], $this->surnameOf($primaryAuthor->name))
                : null;

            // Assign/backfill a cutterNumber for every resolved author; the
            // primary author gets the verified cutter (if found) instead of
            // the local table's guess. Never overwrites an existing cutter,
            // so previously catalogued books keep shelving consistently.
            foreach ($authorEntries as $index => $entry) {
                $this->cutterNumber($entry['model'], $index === 0 ? $verifiedCutter : null);
            }

            // Prefer the live Dewey class for this specific edition; fall
            // back to the subject's keyword-derived classification code.
            $deweyClass = $lookup['deweyClass'] ?? $this->classificationCode($subject);

            // Auto-generate the class number from the Dewey class + the
            // primary author's cutter number, unless the librarian typed
            // one in explicitly. `callNumber` is accepted as an alias for
            // `classNumber` (the Nuxt client's naming).
            $classNumber = $validated['classNumber']
                ?? $validated['callNumber']
                ?? $this->generateUniqueClassNumber($deweyClass, $primaryAuthor->cutterNumber);

            $book = $this->books->create([
                'uuid'            => Str::uuid(),
                'subjectID'       => $subject->subjectID,
                'areasOfLibrary'  => $validated['areasOfLibrary'] ?? 'circulation',
                'title'           => $validated['title'],
                'classNumber'     => $classNumber,
                'isbn'            => $validated['isbn'] ?? null,
                'publicationYear' => $validated['publicationYear'] ?? null,
                'volume'          => $validated['volume'] ?? null,
                'edition'         => $validated['edition'] ?? null,
                'pages'           => $validated['pages'] ?? null,
                'publisher'       => $validated['publisher'] ?? null,
                'sourceOfFund'    => $validated['sourceOfFund'] ?? null,
                'cost'            => $validated['cost'] ?? null,
                'copyNumber'      => $validated['copyNumber'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
                'coverImageURL'   => $validated['coverImageURL'] ?? null,
                'shelfLocation'   => $validated['shelfLocation'] ?? null,
            ]);

            foreach ($authorEntries as $entry) {
                $book->authors()->attach($entry['model']->authorID, [
                    'uuid' => Str::uuid(),
                    'role' => $entry['role'],
                ]);
            }

            for ($i = 0; $i < $validated['quantity']; $i++) {
                $this->createCopy($book->bookID);
            }

            return $book;
        });

        return $this->books->loadCatalogRelations($book);
    }

    public function updateBook(Book $book, array $validated): Book
    {
        $book = DB::transaction(function () use ($validated, $book) {
            if (! empty($validated['subjectID'])) {
                $book->subjectID = $validated['subjectID'];
            } elseif (! empty($validated['subjectName'])) {
                $subject = $this->subjects->firstOrCreateByName($validated['subjectName']);
                $book->subjectID = $subject->subjectID;
            }

            // `callNumber` is accepted as an alias for `classNumber`.
            if (! empty($validated['callNumber']) && empty($validated['classNumber'])) {
                $validated['classNumber'] = $validated['callNumber'];
            }

            $book->fill(collect($validated)->only([
                'title', 'classNumber', 'areasOfLibrary', 'isbn', 'publicationYear', 'volume', 'edition',
                'pages', 'publisher', 'sourceOfFund', 'cost', 'copyNumber', 'remarks',
                'coverImageURL', 'shelfLocation',
            ])->toArray());
            $book->save();

            if (array_key_exists('authors', $validated)) {
                $syncData = [];
                foreach ($validated['authors'] as $authorInput) {
                    $author = ! empty($authorInput['authorID'])
                        ? $this->authors->findOrFail($authorInput['authorID'])
                        : $this->authors->firstOrCreateByName($authorInput['name']);

                    $syncData[$author->authorID] = ['uuid' => Str::uuid(), 'role' => $authorInput['role'] ?? null];
                }
                $book->authors()->sync($syncData);
            }

            if (array_key_exists('quantity', $validated)) {
                $this->adjustCopyQuantity($book, $validated['quantity']);
            }

            return $book;
        });

        return $this->books->loadCatalogRelations($book->fresh());
    }

    public function deleteBook(Book $book): void
    {
        $activeLoans = $this->bookCopies->countByStatusForBook($book->bookID, 'borrowed');

        if ($activeLoans > 0) {
            throw ValidationException::withMessages([
                'book' => ["Cannot delete: {$activeLoans} copy(ies) of this book are currently borrowed."],
            ]);
        }

        $this->books->delete($book);
    }

    private function createCopy(int $bookID): void
    {
        $this->bookCopies->create([
            'uuid'            => Str::uuid(),
            'bookID'          => $bookID,
            'accessionNumber' => $this->bookCopies->generateUniqueAccessionNumber(),
            'barcodeValue'    => $this->bookCopies->generateUniqueBarcode(),
            'status'          => 'available',
        ]);
    }

    private function adjustCopyQuantity(Book $book, int $desiredQuantity): void
    {
        $currentCount = $this->bookCopies->countActiveForBook($book->bookID);

        if ($desiredQuantity > $currentCount) {
            for ($i = 0; $i < $desiredQuantity - $currentCount; $i++) {
                $this->createCopy($book->bookID);
            }

            return;
        }

        if ($desiredQuantity < $currentCount) {
            $toRemove = $currentCount - $desiredQuantity;
            $removable = $this->bookCopies->availableForBook($book->bookID, $toRemove);

            if ($removable->count() < $toRemove) {
                throw ValidationException::withMessages([
                    'quantity' => ["Cannot reduce to {$desiredQuantity}: only {$removable->count()} copies are currently available to retire (others are borrowed, lost, or damaged)."],
                ]);
            }

            foreach ($removable as $copy) {
                $this->bookCopies->update($copy, ['status' => 'retired']);
            }
        }
    }

    /**
     * Returns the author's cutter number. If a verified one was matched from
     * a live Open Library lookup and the author doesn't have one yet, that's
     * used; otherwise falls back to (and persists) a generated one. Never
     * overwrites a cutterNumber the author already has, so existing
     * catalogued books keep shelving consistently.
     */
    private function cutterNumber(Author $author, ?string $verified = null): string
    {
        if (empty($author->cutterNumber)) {
            $this->authors->update($author, [
                'cutterNumber' => $verified ?: $this->generateCutter($author->name),
            ]);
        }

        return $author->cutterNumber;
    }

    /**
     * Returns the subject's Dewey classification code, generating and
     * persisting one first if this is a legacy row created before that
     * feature existed.
     */
    private function classificationCode(BookSubject $subject): string
    {
        if (empty($subject->classificationCode)) {
            $this->subjects->update($subject, [
                'classificationCode' => $this->classifyByName($subject->name),
            ]);
        }

        return $subject->classificationCode;
    }

    /**
     * Maps a subject name to a Dewey Decimal classification code using the
     * table in config/classification.php. First keyword match wins; falls
     * back to the configured default ("000") otherwise.
     */
    private function classifyByName(string $name): string
    {
        $needle = strtolower($name);
        $code = config('classification.dewey.default', '000');

        foreach (config('classification.dewey.map', []) as $keyword => $deweyCode) {
            if (str_contains($needle, $keyword)) {
                $code = $deweyCode;
                break;
            }
        }

        return $this->uniqueSuffixed(
            $code,
            fn (string $candidate) => $this->subjects->classificationCodeExists($candidate)
        );
    }

    /**
     * Builds a class number like "005.13 C67". Falls back through
     * a/b/c... suffixes if that exact classification + cutter combination
     * is already in use.
     */
    private function generateUniqueClassNumber(string $classificationCode, string $cutterNumber): string
    {
        return $this->uniqueSuffixed(
            "{$classificationCode} {$cutterNumber}",
            fn (string $candidate) => $this->books->classNumberExists($candidate)
        );
    }

    /**
     * Appends a/b/c... suffixes to $base until $existsCheck reports no
     * collision. Shared by the classification-code and class-number
     * generators, which both need the same "first free slug" behavior.
     */
    private function uniqueSuffixed(string $base, callable $existsCheck): string
    {
        $candidate = $base;
        $suffix = 0;

        while ($existsCheck($candidate)) {
            $candidate = $base . chr(97 + $suffix);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Generate a Cutter-style author number, e.g. "Cormen" -> "C887". The
     * letter comes from the first letter of the surname; the digits come
     * from a standard Cutter-Sanborn-style range table. Collisions are
     * resolved by consuming more letters of the surname, then by numeric
     * suffix if the surname runs out.
     */
    private function generateCutter(string $name): string
    {
        $surname = $this->surnameOf($name);
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $surname));

        if ($letters === '') {
            throw new \InvalidArgumentException('Cannot generate a cutter number from an empty surname.');
        }

        $firstLetter = $letters[0];
        $isQu = str_starts_with($letters, 'QU');

        $nextIndex = $isQu ? 3 : 2;
        $cutter = $firstLetter . $this->cutterBaseDigit($letters, $firstLetter, $isQu);

        while ($this->authors->cutterNumberExists($cutter)) {
            $nextLetter = $letters[$nextIndex] ?? null;

            if ($nextLetter === null) {
                $suffix = 1;
                $candidate = $cutter . $suffix;
                while ($this->authors->cutterNumberExists($candidate)) {
                    $suffix++;
                    $candidate = $cutter . $suffix;
                }

                return $candidate;
            }

            $cutter .= $this->cutterExpansionDigit($nextLetter);
            $nextIndex++;
        }

        return $cutter;
    }

    private function surnameOf(string $name): string
    {
        if (str_contains($name, ',')) {
            return trim(explode(',', $name)[0]);
        }

        $parts = preg_split('/\s+/', trim($name));

        return end($parts);
    }

    private function cutterBaseDigit(string $letters, string $firstLetter, bool $isQu): int
    {
        if ($isQu) {
            return $this->cutterLookup($letters[2] ?? '', self::CUTTER_TABLE_QU);
        }

        $second = $letters[1] ?? '';

        if (in_array($firstLetter, ['A', 'E', 'I', 'O', 'U'])) {
            return $this->cutterLookup($second, self::CUTTER_TABLE_FIRST_VOWEL);
        }

        if ($firstLetter === 'S') {
            return $this->cutterLookup($second, self::CUTTER_TABLE_FIRST_S);
        }

        return $this->cutterLookup($second, self::CUTTER_TABLE_DEFAULT);
    }

    private function cutterExpansionDigit(string $letter): int
    {
        return $this->cutterLookup($letter, self::CUTTER_EXPANSION_TABLE);
    }

    private function cutterLookup(string $letter, array $ranges): int
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
