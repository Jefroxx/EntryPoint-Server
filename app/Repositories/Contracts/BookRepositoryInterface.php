<?php

namespace App\Repositories\Contracts;

use App\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BookRepositoryInterface extends RepositoryInterface
{
    public function loadCatalogRelations(Book $book): Book;

    public function classNumberExists(string $classNumber): bool;

    public function totalCount(): int;

    public function paginateCatalog(?string $search, int $perPage): LengthAwarePaginator;

    /**
     * Catalog for the student app: each book carries live `total_copies` and
     * `available_copies` counts.
     */
    public function paginateForStudent(?string $search, ?int $subjectID, bool $availableOnly, int $perPage): LengthAwarePaginator;

    public function loadForStudent(Book $book): Book;
}
