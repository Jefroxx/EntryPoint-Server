<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookCopyRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookStockLog;
use App\Services\StockLogService;
use Illuminate\Validation\Rule;
use App\Services\CatalogService;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(private CatalogService $catalog, private StockLogService $stockLog)
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

    public function copies(Request $request)
    {
        $filters = $request->validate([
            'search'    => ['nullable', 'string', 'max:255'],
            'subjectID' => ['nullable', 'integer'],
            'status'    => ['nullable', 'in:available,borrowed,lost,damaged'],
            'area'      => ['nullable', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            'perPage'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->catalog->copyCatalog(
            $filters['search'] ?? null,
            isset($filters['subjectID']) ? (int) $filters['subjectID'] : null,
            $filters['status'] ?? null,
            $filters['area'] ?? null,
            (int) ($filters['perPage'] ?? 15),
        ));
    }

    public function updateCopy(UpdateBookCopyRequest $request, BookCopy $copy)
    {
        $copy = $this->catalog->updateCopyStatus($copy, $request->validated('status'));

        return response()->json([
            'message' => $copy->status === 'retired'
                ? "Accession no. {$copy->accessionNumber} removed from the catalog."
                : "Accession no. {$copy->accessionNumber} updated.",
            'copy' => $copy->only(['copyID', 'accessionNumber', 'status']),
        ]);
    }

    /** Adds more copies of a book that is already in the catalog. */
    public function addCopies(Request $request, Book $book)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);

        $copies = $this->catalog->addCopies($book, $data['quantity'], $data['note'] ?? null);
        $numbers = $copies->pluck('accessionNumber')->implode(', ');

        return response()->json([
            'message' => $copies->count() === 1
                ? "Added accession no. {$numbers}."
                : "Added {$copies->count()} copies (accession nos. {$numbers}).",
            'copies' => $copies->map(fn (BookCopy $copy) => $copy->only(['copyID', 'accessionNumber', 'status']))->values(),
        ], 201);
    }

    /** Removes one copy from the catalog, recording why. */
    public function removeCopy(Request $request, BookCopy $copy)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(BookStockLog::REMOVE_REASONS)],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $copy = $this->catalog->removeCopy($copy, $data['reason'], $data['note'] ?? null);

        return response()->json([
            'message' => "Accession no. {$copy->accessionNumber} removed from the catalog.",
            'copy'    => $copy->only(['copyID', 'accessionNumber', 'status']),
        ]);
    }

    /** The stock log: every copy added or removed, newest first. */
    public function stockLogs(Request $request)
    {
        $filters = $request->validate([
            'search'  => ['nullable', 'string', 'max:255'],
            'action'  => ['nullable', Rule::in(BookStockLog::ACTIONS)],
            'bookID'  => ['nullable', 'integer'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->stockLog->paginate(
            $filters['search'] ?? null,
            $filters['action'] ?? null,
            isset($filters['bookID']) ? (int) $filters['bookID'] : null,
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
