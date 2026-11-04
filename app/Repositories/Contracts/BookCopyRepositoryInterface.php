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

    /** Copies on the shelf right now, including any held for an accepted reservation. */
    public function availableCountForBook(int $bookID): int;

    public function generateUniqueBarcode(): string;

    /**
     * Copies (not retired) with their book and authors, in accession order, for printing labels. Every criterion given
     * narrows the set: `copies` (uuids), `book` (a book's uuid), the catalog's `search` / `subjectID` / `status` /
     * `area`, and `accessions` (a list of ['from' => n, 'to' => m] ranges; a single number is from = to).
     *
     * @param  array<string, mixed>  $criteria
     */
    public function forLabels(array $criteria, int $limit): Collection;

    /** A copy (not retired) by its scanned barcode, or by its accession number when the code is all digits. */
    public function findByScanCode(string $code): ?BookCopy;

    /**
     * The Book Catalog: one row per copy (retired copies excluded), with its book, subject and authors.
     * `search`: all digits matches an accession number exactly (or part of an ISBN); anything else
     * matches the title, ISBN, call number or an author.
     */
    public function paginateCopyCatalog(?string $search, ?int $subjectID, ?string $status, ?string $area, int $perPage): LengthAwarePaginator;
}
