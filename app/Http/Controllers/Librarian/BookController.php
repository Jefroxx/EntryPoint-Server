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
use App\Services\BookMetadataService;
use App\Services\CatalogService;
use App\Services\CirculationService;
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
            'dewey'        => ['nullable', 'in:verified,unverified'],
            'perPage'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->catalog->catalogIndex(
            $filters['search'] ?? null,
            isset($filters['subjectID']) ? (int) $filters['subjectID'] : null,
            $filters['availability'] ?? null,
            (int) ($filters['perPage'] ?? 15),
            $filters['dewey'] ?? null,
        ));
    }

    /** The "check these Dewey numbers" list: books nobody has confirmed a number for yet. */
    public function deweyReview(Request $request)
    {
        $filters = $request->validate([
            'search'  => ['nullable', 'string', 'max:255'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->catalog->deweyReview($filters['search'] ?? null, (int) ($filters['perPage'] ?? 15)));
    }

    /** For the book form's "Look up" button: what the catalogues say about an ISBN. Saves nothing. */
    public function lookupIsbnDewey(Request $request)
    {
        $data = $request->validate(['isbn' => ['required', 'string', 'max:20']]);

        return response()->json($this->catalog->lookupDeweyByIsbn($data['isbn']));
    }

    /**
     * For the Add Book form's scan / "Find details": what the catalogues know about an ISBN (title, authors,
     * publisher, year, pages, description, cover link and Dewey number). Saves nothing; the librarian reviews it.
     * "Not found" is an ordinary answer (local and older books often are), so it is a 200, not an error.
     */
    public function lookupIsbnDetails(Request $request, BookMetadataService $metadata)
    {
        $data = $request->validate(['isbn' => ['required', 'string', 'max:40']]);

        $isbn = BookMetadataService::normalizeIsbn($data['isbn']);

        if ($isbn === null) {
            return response()->json([
                'found'   => false,
                'reason'  => 'invalid',
                'message' => "That isn't a valid ISBN. Check the digits (a book's ISBN has 10 or 13).",
            ]);
        }

        $book = $metadata->lookupByIsbn($isbn);

        if ($book === null) {
            return response()->json([
                'found'   => false,
                'reason'  => 'not_found',
                'isbn'    => $isbn,
                'message' => 'No catalogue has this book. Type its details in below.',
            ]);
        }

        return response()->json(['found' => true] + $book);
    }

    /** Looks the book's ISBN up and, if a Dewey number is found, applies it. */
    public function lookupDewey(Book $book)
    {
        $result = $this->catalog->lookupDewey($book);

        return response()->json($result['found']
            ? ['found' => true, 'message' => "Dewey number set to {$result['book']->deweyNumber}.", 'deweyNumber' => $result['book']->deweyNumber, 'callNumber' => $result['book']->callNumber, 'deweySource' => $result['book']->deweySource]
            : ['found' => false, 'message' => $result['reason']]);
    }

    /** A librarian typed the right number in: it is used as is, and counted as checked. */
    public function setDewey(Request $request, Book $book)
    {
        $data = $request->validate(['deweyNumber' => ['required', 'string', 'regex:/^\d{3}(\.\d{1,10})?$/']]);

        $book = $this->catalog->applyDewey($book, $data['deweyNumber'], 'manual');

        return response()->json(['message' => "Dewey number set to {$book->deweyNumber}.", 'deweyNumber' => $book->deweyNumber, 'callNumber' => $book->callNumber, 'deweySource' => $book->deweySource]);
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

    /** Barcode label data for printing: chosen copies, one book's copies, or everything the catalog filters match. */
    public function copyLabels(Request $request)
    {
        $selection = $request->validate([
            'copies'    => ['nullable', 'array', 'max:'.CatalogService::MAX_LABELS],
            'copies.*'  => ['string', 'max:64'],
            'book'      => ['nullable', 'string', 'max:64'],
            'search'    => ['nullable', 'string', 'max:255'],
            'subjectID' => ['nullable', 'integer'],
            'status'    => ['nullable', 'in:available,borrowed,lost,damaged'],
            'area'      => ['nullable', 'in:circulation,reserved,filipiniana,fiction,thesis,journal,dissertation'],
            // "1-20, 25": ranges of accession numbers (a single number is from = to).
            'accessions'        => ['nullable', 'array', 'max:200'],
            'accessions.*.from' => ['required', 'integer', 'min:1'],
            'accessions.*.to'   => ['required', 'integer', 'gte:accessions.*.from'],
            // Print the call number and Cutter of books whose Dewey number is only a guess (left off by default).
            'includeUnconfirmed' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->catalog->copyLabels($selection));
    }

    /** The copy a scanned barcode (or typed accession number) belongs to, with whether it can be lent. */
    public function lookupCopy(Request $request, CirculationService $circulation)
    {
        $code = trim($request->validate(['code' => ['required', 'string', 'max:100']])['code']);
        $copy = $this->catalog->findCopyByScanCode($code);

        // Library-use-only areas (reserved, thesis...) are caught here, at the scan, not after the student is chosen.
        $terms = $circulation->loanTerms(Book::findOrFail($copy['book']['bookID']));
        if (! $terms['loanable'] && ! $copy['lendable']) {
            $copy['lendable'] = 'This book is for library use only and cannot be loaned out.';
        }

        return response()->json(['copy' => [...$copy, 'dueDate' => $terms['dueDate']]]);
    }

    public function updateCopy(UpdateBookCopyRequest $request, BookCopy $copy)
    {
        $data = $request->validated();

        // Removing the copy ('retired') ignores any detail edits sent with it: it is leaving the catalog anyway.
        $details = array_intersect_key($data, array_flip(['sourceOfFund', 'cost', 'remarks']));
        if ($details && ($data['status'] ?? null) !== 'retired') {
            $copy = $this->catalog->updateCopyDetails($copy, $details);
        }

        if (isset($data['status']) && $data['status'] !== $copy->status) {
            $copy = $this->catalog->updateCopyStatus($copy, $data['status']);
        }

        return response()->json([
            'message' => $copy->status === 'retired'
                ? "Accession no. {$copy->accessionNumber} removed from the catalog."
                : "Accession no. {$copy->accessionNumber} updated.",
            'copy' => $copy->only(['copyID', 'accessionNumber', 'status', 'sourceOfFund', 'cost', 'remarks']),
        ]);
    }

    /**
     * Adds more copies of a book that is already in the catalog. Where they came from is required (source of
     * fund and the cost of each copy; a donation can cost 0), and remarks are the one optional field.
     */
    public function addCopies(Request $request, Book $book)
    {
        $data = $request->validate([
            'quantity'     => ['required', 'integer', 'min:1', 'max:50'],
            'sourceOfFund' => ['required', 'string', 'max:255'],
            'cost'         => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'remarks'      => ['nullable', 'string', 'max:1000'],
        ]);

        $copies = $this->catalog->addCopies($book, $data['quantity'], [
            'sourceOfFund' => trim($data['sourceOfFund']),
            'cost'         => $data['cost'],
            'remarks'      => isset($data['remarks']) && trim($data['remarks']) !== '' ? trim($data['remarks']) : null,
        ]);
        $numbers = $copies->pluck('accessionNumber')->implode(', ');

        return response()->json([
            'message' => $copies->count() === 1
                ? "Added accession no. {$numbers}."
                : "Added {$copies->count()} copies (accession nos. {$numbers}).",
            'copies' => $copies->map(fn (BookCopy $copy) => $copy->only(['copyID', 'accessionNumber', 'status', 'sourceOfFund', 'cost', 'remarks']))->values(),
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

    /** Everything tied to a book, for the "Remove this book" window to show before anything is deleted. */
    public function removalSummary(Book $book)
    {
        return response()->json($this->catalog->removalSummary($book));
    }

    public function destroy(Book $book)
    {
        $this->catalog->deleteBook($book);

        return response()->json(['message' => 'Book removed from catalog.']);
    }
}
