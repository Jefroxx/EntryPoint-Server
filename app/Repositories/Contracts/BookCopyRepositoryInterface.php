<?php

namespace App\Repositories\Contracts;

use App\Models\BookCopy;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Database\Eloquent\Collection;

interface BookCopyRepositoryInterface extends RepositoryInterface
{
    public function lockForUpdateWithBook(int $copyID): BookCopy;

    /**
     * @return BaseCollection<string,int> counts keyed by status
     */
    public function countsByStatus(): BaseCollection;

    public function countActiveForBook(int $bookID): int;

    public function countByStatusForBook(int $bookID, string $status): int;

    public function availableForBook(int $bookID, int $limit): Collection;

    public function hasAvailableForBook(int $bookID): bool;

    public function generateUniqueAccessionNumber(): string;

    public function generateUniqueBarcode(): string;
}
