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
}
