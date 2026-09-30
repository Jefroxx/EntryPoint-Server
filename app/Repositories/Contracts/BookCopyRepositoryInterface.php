<?php

namespace App\Repositories\Contracts;

use App\Models\BookCopy;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function generateUniqueBarcode(): string;

    /**
     * The Book Catalog: one row per copy (retired copies excluded), with its book, subject and authors.
     * `search`: all digits matches an accession number exactly (or part of an ISBN); anything else
     * matches the title, ISBN, call number or an author.
     */
    public function paginateCopyCatalog(?string $search, ?int $subjectID, ?string $status, ?string $area, int $perPage): LengthAwarePaginator;
}
