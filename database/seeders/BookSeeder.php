<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BookSeeder extends Seeder
{
    public function run(): void
    {
        $books = [
            ['title' => 'Clean Code', 'author' => 'Robert C. Martin', 'subject' => 'Computer Science', 'year' => 2008],
            ['title' => 'Introduction to Algorithms', 'author' => 'Thomas H. Cormen', 'subject' => 'Computer Science', 'year' => 2009],
            ['title' => 'The Pragmatic Programmer', 'author' => 'David Thomas', 'subject' => 'Computer Science', 'year' => 1999],
            ['title' => 'Sapiens: A Brief History of Humankind', 'author' => 'Yuval Noah Harari', 'subject' => 'History', 'year' => 2011],
            ['title' => 'Guns, Germs, and Steel', 'author' => 'Jared Diamond', 'subject' => 'History', 'year' => 1997],
            ['title' => 'The Origin of Species', 'author' => 'Charles Darwin', 'subject' => 'Science', 'year' => 1859],
            ['title' => 'A Brief History of Time', 'author' => 'Stephen Hawking', 'subject' => 'Science', 'year' => 1988],
            ['title' => 'Cosmos', 'author' => 'Carl Sagan', 'subject' => 'Science', 'year' => 1980],
            ['title' => '1984', 'author' => 'George Orwell', 'subject' => 'Fiction', 'year' => 1949],
            ['title' => 'Animal Farm', 'author' => 'George Orwell', 'subject' => 'Fiction', 'year' => 1945],
            ['title' => 'To Kill a Mockingbird', 'author' => 'Harper Lee', 'subject' => 'Fiction', 'year' => 1960],
            ['title' => 'Pride and Prejudice', 'author' => 'Jane Austen', 'subject' => 'Fiction', 'year' => 1813],
            ['title' => 'The Great Gatsby', 'author' => 'F. Scott Fitzgerald', 'subject' => 'Fiction', 'year' => 1925],
            ['title' => 'The Alchemist', 'author' => 'Paulo Coelho', 'subject' => 'Fiction', 'year' => 1988],
            ['title' => 'Atomic Habits', 'author' => 'James Clear', 'subject' => 'Self-Help', 'year' => 2018],
            ['title' => 'Thinking, Fast and Slow', 'author' => 'Daniel Kahneman', 'subject' => 'Psychology', 'year' => 2011],
            ['title' => 'The Design of Everyday Things', 'author' => 'Don Norman', 'subject' => 'Design', 'year' => 1988],
            ['title' => 'Meditations', 'author' => 'Marcus Aurelius', 'subject' => 'Philosophy', 'year' => 180],
            // No CE publication year applies to the ancient original, and the
            // publicationYear column is an unsigned CE-only field — left null
            // rather than storing a fabricated or negative (BCE) year.
            ['title' => 'The Republic', 'author' => 'Plato', 'subject' => 'Philosophy', 'year' => null],
            ['title' => 'Freakonomics', 'author' => 'Steven D. Levitt', 'subject' => 'Economics', 'year' => 2005],
        ];

        $created = 0;

        foreach ($books as $index => $data) {
            if (Book::where('title', $data['title'])->exists()) {
                $this->command?->warn("BookSeeder: \"{$data['title']}\" already exists — skipping.");
                continue;
            }

            $subject = BookSubject::firstOrCreate(
                ['name' => $data['subject']],
                ['uuid' => Str::uuid()]
            );

            if (empty($subject->classificationCode)) {
                $subject->classificationCode = BookSubject::classifyByName($subject->name);
                $subject->save();
            }

            $author = Author::firstOrCreate(
                ['name' => $data['author']],
                ['uuid' => Str::uuid()]
            );

            // Call numbers need *a* year to shelve by even when the real
            // publication year isn't stored (e.g. an ancient text with no
            // CE date) — matches BookController::store()'s own fallback.
            $callNumberYear = $data['year'] ?? now()->year;
            $callNumber = $this->generateUniqueCallNumber($subject->classificationCode, $author->authorNumber, $callNumberYear);

            $book = Book::create([
                'uuid'            => Str::uuid(),
                'subjectID'       => $subject->subjectID,
                'title'           => $data['title'],
                'callNumber'      => $callNumber,
                'publicationYear' => $data['year'],
                'shelfLocation'   => 'Aisle ' . (($index % 5) + 1),
            ]);

            $book->authors()->attach($author->authorID, [
                'uuid' => Str::uuid(),
                'role' => null,
            ]);

            $quantity = random_int(1, 5);
            for ($i = 0; $i < $quantity; $i++) {
                BookCopy::create([
                    'uuid'            => Str::uuid(),
                    'bookID'          => $book->bookID,
                    'accessionNumber' => $this->generateUniqueAccessionNumber(),
                    'barcodeValue'    => BookCopy::generateUniqueBarcode(),
                    'status'          => 'available',
                ]);
            }

            $this->command?->info("BookSeeder: added \"{$data['title']}\" (bookID {$book->bookID}, {$quantity} copies).");
            $created++;
        }

        $this->command?->info("BookSeeder: finished — {$created} new book(s) added, now " . Book::count() . ' total in books table.');
    }

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

    private function generateUniqueAccessionNumber(): string
    {
        do {
            $number = 'ACC-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (BookCopy::where('accessionNumber', $number)->exists());

        return $number;
    }
}
