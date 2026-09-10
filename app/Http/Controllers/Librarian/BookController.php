<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    public function store(StoreBookRequest $request)
    {
        $validated = $request->validated();

        $book = DB::transaction(function () use ($validated) {
            // Resolve category: existing ID or find-or-create by name
            if (! empty($validated['categoryID'])) {
                $categoryID = $validated['categoryID'];
            } else {
                $category = BookCategory::firstOrCreate(
                    ['name' => $validated['categoryName']],
                    ['uuid' => Str::uuid()]
                );
                $categoryID = $category->categoryID;
            }

            // Create the book (title-level record)
            $book = Book::create([
                'uuid'          => Str::uuid(),
                'categoryID'    => $categoryID,
                'title'         => $validated['title'],
                'callNumber'    => $validated['callNumber'],
                'coverImageURL' => $validated['coverImageURL'] ?? null,
                'shelfLocation' => $validated['shelfLocation'] ?? null,
            ]);

            // Resolve authors: existing IDs or find-or-create by name, attach with role
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

                $book->authors()->attach($authorID, [
                    'uuid' => Str::uuid(),
                    'role' => $authorInput['role'] ?? null,
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

            $book->fill(collect($validated)->only(['title', 'callNumber', 'coverImageURL', 'shelfLocation'])->toArray());
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
}
