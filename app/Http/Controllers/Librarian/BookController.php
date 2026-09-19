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
        return response()->json(
            $this->catalog->catalogIndex($request->query('search'), (int) ($request->query('perPage') ?? 15))
        );
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
