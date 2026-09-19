<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = BookSubject::factory()->count(18)->create();
        $authors = Author::factory()->count(60)->create();

        // A lot of books, as requested — each with 1-4 copies in varied
        // states so the dashboard/catalog stats have something to show.
        Book::factory()
            ->count(150)
            ->create(['subjectID' => fn () => $subjects->random()->subjectID])
            ->each(function (Book $book) use ($authors) {
                $authorCount = random_int(1, 2);
                $picked = $authors->random($authorCount);

                foreach (($authorCount === 1 ? [$picked] : $picked->all()) as $index => $author) {
                    $book->authors()->attach($author->authorID, [
                        'uuid' => Str::uuid(),
                        'role' => $index === 0 ? null : 'Co-Author',
                    ]);
                }

                $copyCount = random_int(1, 4);
                $statusRoll = random_int(1, 100);

                for ($i = 0; $i < $copyCount; $i++) {
                    $status = match (true) {
                        $statusRoll <= 70 => 'available',
                        $statusRoll <= 85 => 'borrowed',
                        $statusRoll <= 93 => 'retired',
                        $statusRoll <= 97 => 'damaged',
                        default            => 'lost',
                    };

                    BookCopy::factory()->create([
                        'bookID' => $book->bookID,
                        'status' => $i === 0 ? $status : 'available',
                    ]);
                }
            });
    }
}
