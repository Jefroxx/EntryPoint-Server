<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookStockLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * The librarian's visual record of stock: when copies were added to the catalog and when they were
 * removed, with the reason and who did it.
 */
class StockLogService
{
    public function added(Book $book, BookCopy $copy, string $reason, ?string $note = null): BookStockLog
    {
        return $this->record($book, $copy, 'added', $reason, $note);
    }

    public function removed(Book $book, BookCopy $copy, string $reason, ?string $note = null): BookStockLog
    {
        return $this->record($book, $copy, 'removed', $reason, $note);
    }

    public function paginate(?string $search, ?string $action, ?int $bookID, int $perPage): LengthAwarePaginator
    {
        return BookStockLog::query()
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($bookID, fn ($query) => $query->where('bookID', $bookID))
            ->when($search, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('bookTitle', 'like', "%{$term}%")
                        ->orWhere('reason', 'like', "%{$term}%")
                        ->orWhere('note', 'like', "%{$term}%")
                        ->orWhere('librarianName', 'like', "%{$term}%");

                    if (ctype_digit($term)) {
                        $inner->orWhere('accessionNumber', $term);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('logID')
            ->paginate($perPage);
    }

    private function record(Book $book, BookCopy $copy, string $action, string $reason, ?string $note): BookStockLog
    {
        $user = Auth::user();

        return BookStockLog::create([
            'bookID'          => $book->bookID,
            'bookTitle'       => $book->title,
            'copyID'          => $copy->copyID,
            'accessionNumber' => (string) $copy->accessionNumber,
            'action'          => $action,
            'reason'          => $reason,
            'note'            => $note,
            'librarianID'     => $user?->getKey(),
            'librarianName'   => $user?->fullName,
        ]);
    }
}
