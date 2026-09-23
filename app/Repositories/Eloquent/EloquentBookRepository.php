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

    public function paginateForStudent(?string $search, ?int $subjectID, bool $availableOnly, int $perPage): LengthAwarePaginator
    {
        return Book::with(['subject', 'authors'])
            ->withCount($this->copyCounts())
            ->when($search, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('classNumber', 'like', "%{$term}%")
                        ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('subject', fn ($subjects) => $subjects->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($subjectID, fn ($query, $id) => $query->where('subjectID', $id))
            ->when($availableOnly, fn ($query) => $query->whereHas('copies', fn ($copies) => $copies->where('status', 'available')))
            ->orderBy('title')
            ->paginate($perPage);
    }

    public function loadForStudent(Book $book): Book
    {
        return $book->load(['subject', 'authors'])->loadCount($this->copyCounts());
    }

    private function copyCounts(): array
    {
        return [
            'copies as total_copies'     => fn ($query) => $query->where('status', '!=', 'retired'),
            'copies as available_copies' => fn ($query) => $query->where('status', 'available'),
        ];
    }
}
