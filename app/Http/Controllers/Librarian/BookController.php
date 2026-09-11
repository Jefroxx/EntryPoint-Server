<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Services\LibraryClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    public function __construct(private LibraryClassificationService $classification)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'     => ['nullable', 'string', 'max:255'],
            'categoryID' => ['nullable', 'integer', 'exists:book_categories,categoryID'],
            'perPage'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $books = Book::with(['category', 'authors', 'copies'])
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('title', 'like', "%{$search}%"))
            ->when($validated['categoryID'] ?? null, fn ($query, $categoryID) => $query->where('categoryID', $categoryID))
            ->orderByDesc('bookID')
            ->paginate($validated['perPage'] ?? 15);

        return response()->json($books);
    }

    public function show(Book $book)
    {
        return response()->json([
            'book' => $book->load(['category', 'authors', 'copies']),
        ]);
    }

    public function store(StoreBookRequest $request)
    {
        $validated = $request->validated();

        // Best-effort live lookup, done before the DB transaction opens (a
        // network call has no business holding DB locks). Returns null on
        // any miss/failure/timeout — never blocks the book from being added.
        $lookup = ! empty($validated['isbn'])
            ? $this->classification->lookupByIsbn($validated['isbn'])
            : null;

        $book = DB::transaction(function () use ($validated, $lookup) {
            // Resolve category: existing ID or find-or-create by name.
            // A new category is auto-assigned a Dewey classificationCode from
            // the keyword table (see BookCategory::booted()); a legacy row
            // that predates that feature is backfilled lazily below.
            if (! empty($validated['categoryID'])) {
                $category = BookCategory::findOrFail($validated['categoryID']);
            } else {
                $category = BookCategory::firstOrCreate(
                    ['name' => $validated['categoryName']],
                    ['uuid' => Str::uuid()]
                );
            }

            // Resolve authors first (existing IDs or find-or-create by name) so we
            // know the primary author before building the call number.
            $authorEntries = [];
            foreach ($validated['authors'] as $authorInput) {
                if (! empty($authorInput['authorID'])) {
                    $author = Author::findOrFail($authorInput['authorID']);
                } else {
                    $author = Author::firstOrCreate(
                        ['name' => $authorInput['name']],
                        ['uuid' => Str::uuid()]
                    );
                }

                $authorEntries[] = ['model' => $author, 'role' => $authorInput['role'] ?? null];
            }

            $primaryAuthor = $authorEntries[0]['model'];

            // If the lookup found a real LC classification, prefer whichever
            // candidate's letter matches the primary author's surname.
            $verifiedCutter = $lookup
                ? $this->classification->matchCutterForSurname($lookup['cutterCandidates'], Author::surnameOf($primaryAuthor->name))
                : null;

            // Assign/backfill an authorNumber for every resolved author; the
            // primary author gets the verified cutter (if we found one)
            // instead of the local table's guess.
            foreach ($authorEntries as $index => $entry) {
                $this->authorNumber($entry['model'], $index === 0 ? $verifiedCutter : null);
            }

            $year = $validated['publicationYear'] ?? $lookup['publishYear'] ?? now()->year;

            // Prefer the live Dewey class for this specific edition; fall back
            // to the category's keyword-derived classification code.
            $deweyClass = $lookup['deweyClass'] ?? $this->classificationCode($category);

            // Auto-generate the call number from Dewey class + primary author's
            // cutter number + year, unless the librarian supplied one explicitly.
            $callNumber = $validated['callNumber']
                ?? $this->generateUniqueCallNumber($deweyClass, $primaryAuthor->authorNumber, $year);

            // Create the book (title-level record)
            $book = Book::create([
                'uuid'            => Str::uuid(),
                'categoryID'      => $category->categoryID,
                'title'           => $validated['title'],
                'callNumber'      => $callNumber,
                'isbn'            => $validated['isbn'] ?? null,
                'publicationYear' => $year,
                'coverImageURL'   => $validated['coverImageURL'] ?? null,
                'shelfLocation'   => $validated['shelfLocation'] ?? null,
            ]);

            foreach ($authorEntries as $entry) {
                $book->authors()->attach($entry['model']->authorID, [
                    'uuid' => Str::uuid(),
                    'role' => $entry['role'],
                ]);
            }

            // Generate N physical copies based on quantity
            for ($i = 0; $i < $validated['quantity']; $i++) {
                BookCopy::create([
                    'uuid'            => Str::uuid(),
                    'bookID'          => $book->bookID,
                    'accessionNumber' => $this->generateUniqueAccessionNumber(),
                    'barcodeValue'    => BookCopy::generateUniqueBarcode(),
                    'status'          => 'available',
                ]);
            }

            return $book;
        });
        return response()->json([
            'message' => 'Book added successfully.',
            'book'    => $book->load(['category', 'authors', 'copies']),
        ], 201);
    }

    public function update(UpdateBookRequest $request, Book $book)
    {
        $validated = $request->validated();

        $book = DB::transaction(function () use ($validated, $book) {
            if (! empty($validated['categoryID'])) {
                $book->categoryID = $validated['categoryID'];
            } elseif (! empty($validated['categoryName'])) {
                $category = BookCategory::firstOrCreate(
                    ['name' => $validated['categoryName']],
                    ['uuid' => Str::uuid()]
                );
                $book->categoryID = $category->categoryID;
            }

            $book->fill(collect($validated)->only(['title', 'callNumber', 'publicationYear', 'coverImageURL', 'shelfLocation'])->toArray());
            $book->save();

            if (array_key_exists('authors', $validated)) {
                $syncData = [];
                foreach ($validated['authors'] as $authorInput) {
                    if (! empty($authorInput['authorID'])) {
                        $authorID = $authorInput['authorID'];
                    } else {
                        $author = Author::firstOrCreate(
                            ['name' => $authorInput['name']],
                            ['uuid' => Str::uuid()]
                        );
                        $authorID = $author->authorID;
                    }
                    $syncData[$authorID] = ['uuid' => Str::uuid(), 'role' => $authorInput['role'] ?? null];
                }
                $book->authors()->sync($syncData);
            }

            if (array_key_exists('quantity', $validated)) {
                $this->adjustCopyQuantity($book, $validated['quantity']);
            }

            return $book;
        });

        return response()->json([
            'message' => 'Book updated successfully.',
            'book'    => $book->fresh()->load(['category', 'authors', 'copies']),
        ]);
    }

    public function destroy(Book $book)
    {
        $activeLoans = $book->copies()->where('status', 'borrowed')->count();

        if ($activeLoans > 0) {
            return response()->json([
                'message' => "Cannot delete: {$activeLoans} copy(ies) of this book are currently borrowed.",
            ], 422);
        }

        $book->delete(); // soft delete

        return response()->json(['message' => 'Book removed from catalog.']);
    }

    private function adjustCopyQuantity(Book $book, int $desiredQuantity): void
    {
        $currentCount = $book->copies()->where('status', '!=', 'retired')->count();

        if ($desiredQuantity > $currentCount) {
            $toAdd = $desiredQuantity - $currentCount;
            for ($i = 0; $i < $toAdd; $i++) {
                BookCopy::create([
                    'uuid'            => Str::uuid(),
                    'bookID'          => $book->bookID,
                    'accessionNumber' => $this->generateUniqueAccessionNumber(),
                    'barcodeValue'    => BookCopy::generateUniqueBarcode(),
                    'status'          => 'available',
                ]);
            }
        } elseif ($desiredQuantity < $currentCount) {
            $toRemove = $currentCount - $desiredQuantity;
            $removable = $book->copies()->where('status', 'available')->limit($toRemove)->get();

            if ($removable->count() < $toRemove) {
                throw ValidationException::withMessages([
                    'quantity' => ["Cannot reduce to {$desiredQuantity}: only {$removable->count()} copies are currently available to retire (others are borrowed, lost, or damaged)."],
                ]);
            }

            foreach ($removable as $copy) {
                $copy->update(['status' => 'retired']);
            }
        }
    }

    private function generateUniqueAccessionNumber(): string
    {
        do {
            $number = 'ACC-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (BookCopy::where('accessionNumber', $number)->exists());

        return $number;
    }

    /**
     * Returns the author's cutter number. If a verified one was matched from
     * a live Open Library lookup and the author doesn't have one yet, that's
     * used; otherwise falls back to (and persists) the local table's guess.
     * Never overwrites an authorNumber the author already has, so existing
     * catalogued books keep shelving consistently.
     */
    private function authorNumber(Author $author, ?string $verified = null): string
    {
        if (empty($author->authorNumber)) {
            $author->authorNumber = $verified ?: Author::generateAuthorNumber($author->name);
            $author->save();
        }

        return $author->authorNumber;
    }

    /**
     * Returns the category's Dewey classification code, generating and
     * persisting one first if this is a legacy row created before that
     * feature existed.
     */
    private function classificationCode(BookCategory $category): string
    {
        if (empty($category->classificationCode)) {
            $category->classificationCode = BookCategory::classifyByName($category->name);
            $category->save();
        }

        return $category->classificationCode;
    }

    /**
     * Builds a call number like "005.1 .C662 2009". Falls back through
     * a/b/c... suffixes (matching real LC practice) if that exact
     * classification + cutter + year combination is already in use.
     */
    private function generateUniqueCallNumber(string $classificationCode, string $authorNumber, int $year): string
    {
        $base = "{$classificationCode} .{$authorNumber} {$year}";
        $candidate = $base;
        $suffix = 0;

        while (Book::withTrashed()->where('callNumber', $candidate)->exists()) {
            $candidate = $base . chr(97 + $suffix);
            $suffix++;
        }

        return $candidate;
    }
}
