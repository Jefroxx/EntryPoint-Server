<?php

namespace App\Repositories\Eloquent;

use App\Models\Book;
use App\Repositories\Contracts\BookRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentBookRepository extends BaseRepository implements BookRepositoryInterface
{
    public function __construct(Book $model)
    {
        parent::__construct($model);
    }

    public function loadCatalogRelations(Book $book): Book
    {
        return $book->load(['subject', 'authors', 'copies']);
    }

    public function classNumberExists(string $classNumber): bool
    {
        return Book::withTrashed()->where('classNumber', $classNumber)->exists();
    }

    public function totalCount(): int
    {
        return Book::count();
    }

    public function paginateCatalog(?string $search, int $perPage): LengthAwarePaginator
    {
        return Book::with(['subject', 'authors', 'copies'])
            ->when($search, fn ($query, $term) => $query->where('title', 'like', "%{$term}%"))
            ->orderByDesc('bookID')
            ->paginate($perPage);
    }
}
