<?php

namespace App\Repositories\Eloquent;

use App\Models\Wishlist;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentWishlistRepository extends BaseRepository implements WishlistRepositoryInterface
{
    public function __construct(Wishlist $model)
    {
        parent::__construct($model);
    }

    public function cartForStudent(int $studentID): Collection
    {
        return Wishlist::where('studentID', $studentID)
            ->where('inCart', true)
            ->with('book.subject', 'book.authors')
            ->get();
    }

    public function wishlistForStudent(int $studentID): Collection
    {
        return Wishlist::where('studentID', $studentID)
            ->with(['book.subject', 'book.authors', 'book.copies'])
            ->orderByDesc('addedAt')
            ->get();
    }

    public function lockCartForStudent(int $studentID): Collection
    {
        return Wishlist::where('studentID', $studentID)
            ->where('inCart', true)
            ->lockForUpdate()
            ->get();
    }

    public function findByStudentAndBook(int $studentID, int $bookID): ?Wishlist
    {
        return Wishlist::where('studentID', $studentID)
            ->where('bookID', $bookID)
            ->first();
    }

    public function cartCountForStudent(int $studentID, bool $lock = false): int
    {
        $query = Wishlist::where('studentID', $studentID)->where('inCart', true);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->count();
    }

    public function deleteCartForStudent(int $studentID): void
    {
        Wishlist::where('studentID', $studentID)->where('inCart', true)->delete();
    }

    public function existsForStudentAndBook(int $studentID, int $bookID): bool
    {
        return Wishlist::where('studentID', $studentID)->where('bookID', $bookID)->exists();
    }
}
