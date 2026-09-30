<?php

namespace App\Repositories\Contracts;

use App\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BookRepositoryInterface extends RepositoryInterface
{
    public function loadCatalogRelations(Book $book): Book;

    public function classNumberExists(string $classNumber): bool;

    public function totalCount(): int;

    /**
     * The librarian's catalog. `$availability` is 'available' (at least one copy on the shelf) or
     * 'unavailable' (none on the shelf); null means both. Retired copies are left off each book.
     */
    public function paginateCatalog(?string $search, ?int $subjectID, ?string $availability, int $perPage): LengthAwarePaginator;

    /**
     * Catalog for the student app: each book carries live `total_copies` and
     * `available_copies` counts.
     */
    public function paginateForStudent(?string $search, ?int $subjectID, bool $availableOnly, int $perPage): LengthAwarePaginator;

    public function loadForStudent(Book $book): Book;
}
