<?php

namespace App\Repositories\Eloquent;

use App\Models\BookCopy;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;

class EloquentBookCopyRepository extends BaseRepository implements BookCopyRepositoryInterface
{
    public function __construct(BookCopy $model)
    {
        parent::__construct($model);
    }

    public function lockForUpdateWithBook(int $copyID): BookCopy
    {
        return BookCopy::with('book')->lockForUpdate()->findOrFail($copyID);
    }

    public function countActiveForBook(int $bookID): int
    {
        return BookCopy::where('bookID', $bookID)->where('status', '!=', 'retired')->count();
    }

    public function countByStatusForBook(int $bookID, string $status): int
    {
        return BookCopy::where('bookID', $bookID)->where('status', $status)->count();
    }

    public function availableForBook(int $bookID, int $limit): Collection
    {
        return BookCopy::where('bookID', $bookID)
            ->where('status', 'available')
            ->limit($limit)
            ->get();
    }

    public function hasAvailableForBook(int $bookID): bool
    {
        return BookCopy::where('bookID', $bookID)->where('status', 'available')->exists();
    }

    public function availableCountForBook(int $bookID): int
    {
        return BookCopy::where('bookID', $bookID)->where('status', 'available')->count();
    }

    public function countsByStatus(): BaseCollection
    {
        return BookCopy::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    public function paginateCopyCatalog(?string $search, ?int $subjectID, ?string $status, ?string $area, int $perPage): LengthAwarePaginator
    {
        return $this->catalogQuery($search, $subjectID, $status, $area)
            ->orderBy('accessionNumber')
            ->paginate($perPage);
    }

    /** The Book Catalog's search and filters as a query, shared by the table and by label printing. */
    private function catalogQuery(?string $search, ?int $subjectID, ?string $status, ?string $area)
    {
        // whereHas('book') also drops copies whose book was removed (books are soft-deleted).
        return BookCopy::with(['book.subject', 'book.authors'])
            ->where('status', '!=', 'retired')
            ->whereHas('book', function ($books) use ($subjectID, $area) {
                $books->when($subjectID, fn ($query, $id) => $query->where('subjectID', $id))
                    ->when($area, fn ($query, $value) => $query->where('areaOfLibrary', $value));
            })
            ->when($search, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    // All digits means an accession number (or part of an ISBN), not "7" inside a title or call number.
                    if (ctype_digit($term)) {
                        $inner->where('accessionNumber', (int) $term)
                            ->orWhereHas('book', fn ($books) => $books->where('isbn', 'like', "%{$term}%"));

                        return;
                    }
                    $inner->orWhereHas('book', fn ($books) => $books->where(function ($book) use ($term) {
                        $book->where('title', 'like', "%{$term}%")
                            ->orWhere('isbn', 'like', "%{$term}%")
                            ->orWhereRaw("CONCAT_WS(' ', books.deweyNumber, books.cutter) like ?", ["%{$term}%"])
                            ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$term}%"));
                    }));
                });
            })
            ->when($status, fn ($query, $value) => $query->where('status', $value));
    }

    public function forLabels(array $criteria, int $limit): Collection
    {
        $ranges = $criteria['accessions'] ?? [];

        return $this->catalogQuery($criteria['search'] ?? null, $criteria['subjectID'] ?? null, $criteria['status'] ?? null, $criteria['area'] ?? null)
            ->when($criteria['book'] ?? null, fn ($query, $uuid) => $query->whereHas('book', fn ($books) => $books->where('uuid', $uuid)))
            ->when($criteria['copies'] ?? null, fn ($query, $list) => $query->whereIn('uuid', $list))
            // "1-20, 25": each range or single number is one more way for a copy to qualify.
            ->when($ranges, fn ($query) => $query->where(function ($inner) use ($ranges) {
                foreach ($ranges as $range) {
                    $inner->orWhereBetween('accessionNumber', [(int) $range['from'], (int) $range['to']]);
                }
            }))
            ->orderBy('accessionNumber')
            ->limit($limit)
            ->get();
    }

    public function findByScanCode(string $code): ?BookCopy
    {
        $query = BookCopy::with('book.authors')
            ->where('status', '!=', 'retired')
            ->whereHas('book');

        // A scanned label is the copy's barcode. Typing the accession number by hand also works.
        return ctype_digit($code)
            ? (clone $query)->where('accessionNumber', (int) $code)->first() ?? $query->where('barcodeValue', $code)->first()
            : $query->where('barcodeValue', $code)->first();
    }

    public function generateUniqueBarcode(): string
    {
        do {
            $code = 'BK-' . strtoupper(Str::random(10));
        } while ($this->existsBy('barcodeValue', $code));

        return $code;
    }
}
