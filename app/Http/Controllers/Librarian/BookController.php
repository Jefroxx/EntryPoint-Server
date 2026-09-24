<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Services\CatalogService;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(private CatalogService $catalog)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search'       => ['nullable', 'string', 'max:255'],
            'subjectID'    => ['nullable', 'integer'],
            'availability' => ['nullable', 'in:available,unavailable'],
            'perPage'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->catalog->catalogIndex(
            $filters['search'] ?? null,
            isset($filters['subjectID']) ? (int) $filters['subjectID'] : null,
            $filters['availability'] ?? null,
            (int) ($filters['perPage'] ?? 15),
        ));
    }

    public function show(Book $book)
    {
        return response()->json(['book' => $this->catalog->bookDetail($book)]);
    }

    public function libraryStats()
    {
        return response()->json($this->catalog->libraryStats());
    }

    public function store(StoreBookRequest $request)
    {
        $book = $this->catalog->createBook($request->validated());

        return response()->json([
            'message' => 'Book added successfully.',
            'book'    => $book,
        ], 201);
    }

    public function update(UpdateBookRequest $request, Book $book)
    {
        $book = $this->catalog->updateBook($book, $request->validated());

        return response()->json([
            'message' => 'Book updated successfully.',
            'book'    => $book,
        ]);
    }

    public function destroy(Book $book)
    {
        $this->catalog->deleteBook($book);

        return response()->json(['message' => 'Book removed from catalog.']);
    }
}
