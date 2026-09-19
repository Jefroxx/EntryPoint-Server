<?php

namespace App\Repositories\Eloquent;

use App\Models\BookCopy;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
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

    public function countsByStatus(): BaseCollection
    {
        return BookCopy::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    public function generateUniqueAccessionNumber(): string
    {
        do {
            $number = 'ACC-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while ($this->existsBy('accessionNumber', $number));

        return $number;
    }

    public function generateUniqueBarcode(): string
    {
        do {
            $code = 'BK-' . strtoupper(Str::random(10));
        } while ($this->existsBy('barcodeValue', $code));

        return $code;
    }
}
