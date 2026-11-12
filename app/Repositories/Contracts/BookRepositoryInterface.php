<?php

namespace App\Repositories\Contracts;

use App\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BookRepositoryInterface extends RepositoryInterface
{
    public function loadCatalogRelations(Book $book): Book;

    public function callNumberExists(string $dewey, string $cutter, ?int $exceptBookID = null): bool;

    /**
     * The catalogued book holding any of these ISBNs (compared with hyphens and spaces left out, so "978-0-14-043205-3"
     * and "9780140432053" are the same book). A removed book doesn't count.
     *
     * @param string[] $isbns
     */
    public function findByIsbn(array $isbns): ?Book;

    public function totalCount(): int;

    /**
     * The librarian's catalog. `$availability` is 'available' (at least one copy on the shelf) or
     * 'unavailable' (none on the shelf); null means both. Retired copies are left off each book.
     * `$dewey` is 'verified' (Dewey number confirmed) or 'unverified' (still a guess); null means both.
     */
    public function paginateCatalog(?string $search, ?int $subjectID, ?string $availability, int $perPage, ?string $dewey = null): LengthAwarePaginator;

    /**
     * Catalog for the student app: each book carries live `total_copies` and
     * `available_copies` counts.
     */
    public function paginateForStudent(?string $search, ?int $subjectID, bool $availableOnly, int $perPage, bool $newOnly = false): LengthAwarePaginator;

    public function loadForStudent(Book $book): Book;

    /** Books whose Dewey number has not been checked yet (guessed, or from before real lookups), newest first. */
    public function paginateDeweyReview(?string $search, int $perPage): LengthAwarePaginator;

    public function deweyReviewCount(): int;
}
