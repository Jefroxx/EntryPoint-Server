<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookSubject;
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
            if (! empty($validated['subjectID'])) {
                $subjectID = $validated['subjectID'];
            } else {
                $subject = BookSubject::firstOrCreate(
                    ['name' => $validated['subjectName']],
                    ['uuid' => Str::uuid()]
                );
                $subjectID = $subject->subjectID;
            }

            $book = Book::create([
                'uuid'           => Str::uuid(),
                'subjectID'      => $subjectID,
                'areasOfLibrary' => $validated['areasOfLibrary'] ?? 'circulation',
                'title'          => $validated['title'],
                'classNumber'    => $validated['classNumber'],
                'isbn'           => $validated['isbn'] ?? null,
                'volume'         => $validated['volume'] ?? null,
                'edition'        => $validated['edition'] ?? null,
                'pages'          => $validated['pages'] ?? null,
                'publisher'      => $validated['publisher'] ?? null,
                'sourceOfFund'   => $validated['sourceOfFund'] ?? null,
                'cost'           => $validated['cost'] ?? null,
                'copyNumber'     => $validated['copyNumber'] ?? null,
                'remarks'        => $validated['remarks'] ?? null,
                'coverImageURL'  => $validated['coverImageURL'] ?? null,
                'shelfLocation'  => $validated['shelfLocation'] ?? null,
            ]);

            foreach ($validated['authors'] as $authorInput) {
                if (! empty($authorInput['authorID'])) {
                    $authorID = $authorInput['authorID'];
                } else {
                    $author = Author::firstOrCreate(
                        ['name' => $authorInput['name']],
                        [
                            'uuid'         => Str::uuid(),
                            'cutterNumber' => Author::generateCutter($authorInput['name']),
                        ]
                    );
                    $authorID = $author->authorID;
                }

                $book->authors()->attach($authorID, [
                    'uuid' => Str::uuid(),
                    'role' => $authorInput['role'] ?? null,
                ]);
            }

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
            'book'    => $book->load(['subject', 'authors', 'copies']),
        ], 201);
    }

    public function update(UpdateBookRequest $request, Book $book)
    {
        $validated = $request->validated();

        $book = DB::transaction(function () use ($validated, $book) {
            if (! empty($validated['subjectID'])) {
                $book->subjectID = $validated['subjectID'];
            } elseif (! empty($validated['subjectName'])) {
                $subject = BookSubject::firstOrCreate(
                    ['name' => $validated['subjectName']],
                    ['uuid' => Str::uuid()]
                );
                $book->subjectID = $subject->subjectID;
            }

            $book->fill(collect($validated)->only([
                'title', 'classNumber', 'areasOfLibrary', 'isbn', 'volume', 'edition',
                'pages', 'publisher', 'sourceOfFund', 'cost', 'copyNumber', 'remarks',
                'coverImageURL', 'shelfLocation',
            ])->toArray());
            $book->save();

            if (array_key_exists('authors', $validated)) {
                $syncData = [];
                foreach ($validated['authors'] as $authorInput) {
                    if (! empty($authorInput['authorID'])) {
                        $authorID = $authorInput['authorID'];
                    } else {
                        $author = Author::firstOrCreate(
                            ['name' => $authorInput['name']],
                            [
                                'uuid'         => Str::uuid(),
                                'cutterNumber' => Author::generateCutter($authorInput['name']),
                            ]
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
            'book'    => $book->fresh()->load(['subject', 'authors', 'copies']),
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

        $book->delete();

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
