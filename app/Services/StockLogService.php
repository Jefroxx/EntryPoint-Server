<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookStockLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * The librarian's visual record of stock: when copies were added to the catalog and when they were
 * removed, with the reason and who did it, and every change made to a copy or a book's record in between
 * (status, cost, source of fund, remarks, title, area, Dewey number...) as "edited".
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

    /** A correction to one copy's own record (cost, source of fund, remarks); the note says what changed. */
    public function edited(Book $book, BookCopy $copy, string $note): BookStockLog
    {
        return $this->record($book, $copy, 'edited', 'Edited details', $note);
    }

    /** A copy's status changed by hand or at the return desk (on the shelf, damaged, lost); the note says from what to what. */
    public function statusChanged(Book $book, BookCopy $copy, ?string $before, string $after, ?string $reason = null): ?BookStockLog
    {
        if ($before === $after) {
            return null;
        }

        return $this->record($book, $copy, 'edited', 'Status changed', 'Status: '.self::statusLabel($before).' → '.self::statusLabel($after).($reason ? '. Reason: '.$reason : ''));
    }

    /** A change to the book's own record (title, area, Dewey number, authors...): not one copy's, so no accession number. */
    public function bookEdited(Book $book, string $note): BookStockLog
    {
        return $this->record($book, null, 'edited', 'Book details edited', $note);
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'available' => 'On the shelf',
            'borrowed'  => 'Borrowed',
            'damaged'   => 'Damaged',
            'lost'      => 'Lost',
            'retired'   => 'Removed',
            default     => $status ?: '—',
        };
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

    private function record(Book $book, ?BookCopy $copy, string $action, string $reason, ?string $note): BookStockLog
    {
        $user = Auth::user();

        return BookStockLog::create([
            'bookID'          => $book->bookID,
            'bookTitle'       => $book->title,
            'copyID'          => $copy?->copyID,
            'accessionNumber' => $copy ? (string) $copy->accessionNumber : null,
            'action'          => $action,
            'reason'          => $reason,
            'note'            => $note,
            'librarianID'     => $user?->getKey(),
            'librarianName'   => $user?->fullName,
        ]);
    }
}
