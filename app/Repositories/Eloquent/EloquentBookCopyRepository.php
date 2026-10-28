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
                            ->orWhere('classNumber', 'like', "%{$term}%")
                            ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$term}%"));
                    }));
                });
            })
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderBy('accessionNumber')
            ->paginate($perPage);
    }

    public function generateUniqueBarcode(): string
    {
        do {
            $code = 'BK-' . strtoupper(Str::random(10));
        } while ($this->existsBy('barcodeValue', $code));

        return $code;
    }
}
