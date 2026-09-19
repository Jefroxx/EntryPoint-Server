<?php

namespace App\Repositories\Contracts;

use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;

interface WishlistRepositoryInterface extends RepositoryInterface
{
    public function cartForStudent(int $studentID): Collection;

    public function lockCartForStudent(int $studentID): Collection;

    public function wishlistForStudent(int $studentID): Collection;

    public function findByStudentAndBook(int $studentID, int $bookID): ?Wishlist;

    public function cartCountForStudent(int $studentID, bool $lock = false): int;

    public function deleteCartForStudent(int $studentID): void;

    public function existsForStudentAndBook(int $studentID, int $bookID): bool;
}
