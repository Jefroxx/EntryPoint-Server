<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\CatalogService;
use Illuminate\Console\Command;

class CheckBookDewey extends Command
{
    protected $signature = 'books:check-dewey {--limit=0 : Stop after this many books (0 = all)} {--pause=400 : Milliseconds to wait between lookups}';

    protected $description = 'Look up the Dewey number of every book still marked "needs review" by its ISBN. Books with no match stay flagged.';

    public function handle(CatalogService $catalog): int
    {
        $query = Book::where('deweyStatus', 'needs_review')->whereNotNull('isbn')->orderBy('bookID');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $books = $query->get();
        $found = 0;

        $this->info("Checking {$books->count()} book(s)…");

        foreach ($books as $book) {
            $result = $catalog->lookupDewey($book);

            if ($result['found']) {
                $found++;
                $this->line("  ✓ {$book->title}: {$result['book']->deweyNumber} ({$result['book']->deweySource})");
            } else {
                $this->line("  · {$book->title}: still needs review");
            }

            // Neither catalogue publishes usage limits, so go gently.
            usleep(((int) $this->option('pause')) * 1000);
        }

        $left = Book::where('deweyStatus', 'needs_review')->count();
        $this->info("Done: {$found} found, {$left} still need review.");

        return self::SUCCESS;
    }
}
