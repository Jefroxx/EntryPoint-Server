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

    public function paginateCatalog(?string $search, ?int $subjectID, ?string $availability, int $perPage): LengthAwarePaginator
    {
        // "Free" means on the shelf and not held for an accepted reservation.
        $free = "(select count(*) from book_copies where book_copies.bookID = books.bookID and book_copies.status = 'available')
            > (select count(*) from reservations where reservations.bookID = books.bookID and reservations.status = 'Accepted')";

        return Book::with(['subject', 'authors', 'copies' => fn ($copies) => $copies->where('status', '!=', 'retired')])
            ->withCount(['reservations as held_copies' => fn ($reservations) => $reservations->where('status', 'Accepted')])
            // Matches what the search box promises: title, author or ISBN (and call number, for staff).
            ->when($search, function ($query, $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('isbn', 'like', "%{$term}%")
                        ->orWhere('classNumber', 'like', "%{$term}%")
                        ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($subjectID, fn ($query, $id) => $query->where('subjectID', $id))
            ->when($availability === 'available', fn ($query) => $query->whereRaw($free))
            ->when($availability === 'unavailable', fn ($query) => $query->whereRaw("not ({$free})"))
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
            ->when($availableOnly, fn ($query) => $query->whereRaw(
                "(select count(*) from book_copies where book_copies.bookID = books.bookID and book_copies.status = 'available')
                > (select count(*) from reservations where reservations.bookID = books.bookID and reservations.status = 'Accepted')"
            ))
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
            'reservations as held_copies' => fn ($query) => $query->where('status', 'Accepted'),
        ];
    }
}
