<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookCopy;
use App\Services\BookDetailsCheckService;
use App\Services\BookImportService;
use Illuminate\Database\Seeder;

/**
 * The demo catalog: a cross-section of the library's real accession record (data/accession-record-sample.json), brought
 * in through the same import as the Import button, so the books look exactly as an import leaves them. It shows every
 * case the import handles: each area of the library, titles with several copies, new books with ISBNs and dates
 * received, fiction shelved by author mark, a class that can't be read, books with no class, a discarded copy, and a
 * title with no author.
 *
 * About a dozen titles then get the details Check Details found for them (stored in the file, so seeding never goes
 * online), with the library's fund and cost: those are complete. The rest stay "Incomplete", so Check Details has
 * books to work on in a demo.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $file = json_decode(file_get_contents(__DIR__.'/data/accession-record-sample.json'), true);

        app(BookImportService::class)->import($file['rows'], false, '2026 Accession Record.xlsx');

        $details = app(BookDetailsCheckService::class);
        foreach ($file['details'] ?? [] as $accession => $fills) {
            $book = BookCopy::where('accessionNumber', (int) $accession)->first()?->book;
            if (! $book) {
                continue;
            }

            // What the online catalogues found (only empty fields are filled, as in the app)...
            $details->apply([['book' => $book->uuid, 'fills' => $fills['catalogue'] ?? []]], [$book->uuid]);

            // ...and what only the library knows: where the copies came from and what they cost.
            $book = Book::find($book->bookID);
            $book->fill(array_filter([
                'sourceOfFund' => $book->sourceOfFund ?: ($fills['sourceOfFund'] ?? null),
                'cost'         => $book->cost ?? ($fills['cost'] ?? null),
                'edition'      => $book->edition ?: ($fills['edition'] ?? null),
                'volume'       => $book->volume ?: ($fills['volume'] ?? null),
            ], fn ($v) => $v !== null && $v !== ''))->save();
            $book->copies()->whereNull('sourceOfFund')->update(['sourceOfFund' => $book->sourceOfFund]);
            $book->copies()->whereNull('cost')->update(['cost' => $book->cost]);
        }

        // Two books catalogued as Add Book does it, every detail filled in and the Dewey number confirmed. They just
        // arrived, so they also fill the New arrivals shelf on student Home.
        foreach ($file['addedBooks'] ?? [] as $added) {
            $copies = array_fill(0, $added['copies'], [
                'accessionNumber' => null, 'dateReceived' => now()->subDays(3)->toDateString(),
                'sourceOfFund' => $added['sourceOfFund'], 'cost' => $added['cost'], 'remarks' => null, 'status' => 'available',
            ]);
            $book = app(\App\Services\CatalogService::class)->importBook([
                'title' => $added['title'], 'authors' => $added['authors'], 'areaOfLibrary' => $added['areaOfLibrary'],
                'deweyNumber' => $added['deweyNumber'], 'cutter' => null, 'isbn' => $added['isbn'],
                'publicationYear' => $added['publicationYear'], 'edition' => $added['edition'], 'volume' => $added['volume'],
                'pageCount' => $added['pageCount'], 'publisher' => $added['publisher'], 'sourceOfFund' => $added['sourceOfFund'],
                'cost' => $added['cost'], 'remarks' => null,
            ], $copies, null, 'New book');
            $book->update(['deweySource' => 'manual', 'detailsCheckedAt' => now(), 'isNewArrival' => $added['newArrival'], 'newArrivalAt' => $added['newArrival'] ? now()->subDays(3) : null]);
        }
    }
}
