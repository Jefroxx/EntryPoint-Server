<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Check Details: asks the online catalogues for what a book's record lacks, for books that never went through Add
 * Book's scan (the ones brought in from the accession record). Nothing is saved by the check; a librarian reviews what
 * was found and accepts it, and only empty fields are ever filled.
 *
 * A book with an ISBN is looked up by it (Library of Congress, Open Library, Google Books: the Add Book lookup). A book
 * without one is first found by title and author in Open Library, in the edition of the year the record gives when
 * it has one; that edition's ISBN is then looked up the same way. A title match is only accepted when the titles
 * really agree, and the screen says it was matched by title so the librarian can look twice.
 */
class BookDetailsCheckService
{
    /** Fields a catalogue can fill. Source of fund and cost are the library's own, so no catalogue knows them. */
    private const FILLABLE = ['isbn', 'publisher', 'publicationYear', 'pageCount', 'edition', 'volume', 'coverImageURL'];

    public function __construct(
        private BookMetadataService $metadata,
        private CatalogService $catalog,
        private StockLogService $stockLog,
    ) {
    }

    /* ---------------------------------------------------------------- which books */

    /** Books not checked yet that lack something a catalogue could give (or still wait for a Dewey number). */
    public function queueQuery(?string $area = null): Builder
    {
        return Book::query()
            ->whereNull('detailsCheckedAt')
            ->when($area, fn ($q, $a) => $q->where('areaOfLibrary', $a))
            ->where(fn ($q) => $q->whereNull('isbn')->orWhereNull('publisher')->orWhereNull('publicationYear')->orWhereNull('pageCount')
                ->orWhereNull('edition')->orWhereNull('volume')->orWhere('deweyStatus', 'needs_review')->orWhereDoesntHave('authors'));
    }

    public function queue(?string $area, int $perPage, int $page = 1): LengthAwarePaginator
    {
        return $this->queueQuery($area)->with('authors')->orderBy('bookID')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Book $book) => [
                'uuid'            => $book->uuid,
                'title'           => $book->title,
                'authors'         => $book->authors->pluck('name')->values(),
                'isbn'            => $book->isbn,
                'publicationYear' => $book->publicationYear,
                'areaOfLibrary'   => $book->areaOfLibrary,
                'callNumber'      => $book->callNumber,
                'deweyStatus'     => $book->deweyStatus,
            ]);
    }

    /* ---------------------------------------------------------------- checking one book */

    /**
     * What the catalogues know that this book's record lacks. Saves nothing.
     *
     * `$scanned` is an ISBN a librarian just scanned (or typed) from the book in hand: it is looked up instead of the
     * one on record or a title search, because the book itself is the surest source. Only empty fields are offered,
     * so a book that already has a different ISBN keeps it (and the answer says so).
     *
     * @return array{bookUuid: string, found: bool, reason?: string, matchedBy?: string, matched?: array, yearAgrees?: ?bool, isbnTakenBy?: ?string, fills?: array<string, mixed>}
     */
    public function check(Book $book, ?string $scanned = null): array
    {
        $book->loadMissing('authors');
        $authors = $book->authors->pluck('name')->all();

        $isbn = BookMetadataService::normalizeIsbn($book->isbn);
        $match = null;
        $matchedBy = 'isbn';

        if ($scanned !== null) {
            $scannedIsbn = BookMetadataService::normalizeIsbn($scanned);
            if ($scannedIsbn === null) {
                return ['bookUuid' => $book->uuid, 'found' => false, 'reason' => "That isn't a valid ISBN. Scan the barcode on the back cover (978 or 979), or check the digits."];
            }
            $isbn = $scannedIsbn;
            $matchedBy = 'scan';
        } elseif ($isbn === null) {
            $match = $this->searchByTitle($book->title, $authors[0] ?? null, $book->publicationYear);
            if ($match === null) {
                return ['bookUuid' => $book->uuid, 'found' => false, 'reason' => 'No catalogue has a book with this title'.($authors ? ' and author.' : '.')];
            }
            $isbn = $match['isbn'];
            $matchedBy = 'title';
        }

        $found = $this->metadata->lookup($isbn)['book'];
        // A scanned ISBN is still worth keeping when no catalogue knows it: it comes from the book itself.
        if ($found === null && $match === null && $matchedBy !== 'scan') {
            return ['bookUuid' => $book->uuid, 'found' => false, 'reason' => 'No catalogue knows this ISBN.'];
        }

        $details = [
            'isbn'            => $isbn,
            'publisher'       => $found['publisher'] ?? $match['publisher'] ?? null,
            'publicationYear' => $found['publishYear'] ?? $match['year'] ?? null,
            'pageCount'       => $found['pageCount'] ?? null,
            'edition'         => $found['edition'] ?? null,
            'volume'          => $found['volume'] ?? null,
            'coverImageURL'   => $found['coverUrl'] ?? null,
        ];

        $fills = [];
        foreach (self::FILLABLE as $field) {
            if (($book->{$field} === null || $book->{$field} === '') && $details[$field] !== null && $details[$field] !== '') {
                $fills[$field] = $details[$field];
            }
        }
        if (! $authors && ($names = $found['authors'] ?? $match['authors'] ?? [])) {
            $fills['authors'] = array_values($names);
        }
        if (($book->deweyNumber === null || $book->deweyStatus === 'needs_review') && ! empty($found['deweyNumber'])) {
            $fills['deweyNumber'] = $found['deweyNumber'];
            $fills['deweySource'] = $found['deweySource'] ?? 'library_of_congress';
        }

        // The same ISBN is the same book: if another record already has it, this one can't take it.
        $takenBy = null;
        if (isset($fills['isbn']) && ($other = $this->catalog->findBookByIsbn($fills['isbn'])) && $other['bookUuid'] !== $book->uuid) {
            $takenBy = $other['title'];
            unset($fills['isbn']);
        }

        $year = $details['publicationYear'];
        $foundTitle = $found['title'] ?? $match['title'] ?? null;

        return [
            'bookUuid'    => $book->uuid,
            'found'       => true,
            'matchedBy'   => $matchedBy,
            'matched'     => [
                'isbn'      => $isbn,
                'title'     => $found['title'] ?? $match['title'] ?? null,
                'authors'   => $found['authors'] ?? $match['authors'] ?? [],
                'publisher' => $details['publisher'],
                'year'      => $year,
            ],
            // For a title match: does the edition's year agree with the record's? (null when either has none)
            'yearAgrees'  => $book->publicationYear && $year ? (int) $book->publicationYear === (int) $year : null,
            // Does the catalogue's title agree with ours? A mistyped ISBN in the record can be another book's real one,
            // so even an ISBN match whose title disagrees is shown as doubtful and isn't ticked for the librarian.
            'titleAgrees' => $foundTitle ? $this->titleScore($book->title, $foundTitle) > 0 || $this->sameMainTitle($book->title, $foundTitle) : null,
            'isbnTakenBy' => $takenBy,
            // A scan of a book that already has another ISBN on record: the record's is kept, and the screen says so.
            'recordIsbn'  => $matchedBy === 'scan' && $book->isbn && BookMetadataService::normalizeIsbn($book->isbn) !== $isbn ? $book->isbn : null,
            // The scanned ISBN found nothing in the catalogues: only the ISBN itself can be saved.
            'catalogueFound' => $found !== null || $match !== null,
            'fills'       => $fills,
        ];
    }

    /**
     * Open Library's search: the work whose title agrees with ours, in the edition of the record's year when it has
     * one. Returns that edition's ISBN and what the search shows of it, or null. Remembered for 30 days (a miss for a
     * day); a search that didn't answer isn't remembered at all.
     *
     * @return array{isbn: string, title: string, authors: string[], publisher: ?string, year: ?int}|null
     */
    public function searchByTitle(string $title, ?string $author, ?int $year): ?array
    {
        $config = config('classification.open_library');
        $title = trim((string) preg_replace('/\s+/', ' ', str_replace('"', '', $title)));
        if (! ($config['enabled'] ?? true) || $title === '') {
            return null;
        }

        $surname = $author ? $this->surnameOf($author) : null;
        $key = 'ol-title:'.md5(mb_strtolower($title).'|'.$surname.'|'.$year);
        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        // Narrowest first: title + author + the record's year, then without the year. The record's author is sometimes
        // misspelled ("Silberchatz"), so then the title alone, keeping only a book whose author is spelled nearly the
        // same. Every attempt checks the author when the record names one, so a same-titled book by someone else never matches.
        $fields = 'key,title,author_name,editions,editions.isbn,editions.publisher,editions.publish_date,editions.publish_year,editions.title';
        $attempts = [];
        if ($year && $surname) {
            $attempts[] = [['q' => 'title:"'.$title.'" author:'.$surname.' publish_year:'.$year], $surname];
        }
        $attempts[] = [array_filter(['title' => $title, 'author' => $surname]), $surname];
        if ($surname) {
            if ($year) {
                $attempts[] = [['q' => 'title:"'.$title.'" publish_year:'.$year], $surname];
            }
            $attempts[] = [['title' => $title], $surname];
        }

        foreach ($attempts as [$params, $checkAuthor]) {
            try {
                $response = Http::timeout($config['timeout'] ?? 6)->connectTimeout($config['connect_timeout'] ?? 4)->acceptJson()
                    ->withUserAgent('EntryPoint-Library/1.0')
                    ->get(rtrim($config['base_url'], '/').'/search.json', $params + ['fields' => $fields, 'limit' => 8]);
            } catch (Throwable) {
                return null; // didn't answer: not remembered, so the next check asks again
            }
            if (! $response->successful()) {
                return null;
            }
            if ($hit = $this->bestEdition($title, $response->json('docs') ?? [], $checkAuthor)) {
                Cache::put($key, $hit, now()->addDays(30));

                return $hit;
            }
        }

        Cache::put($key, false, now()->addDay());

        return null;
    }

    /**
     * The work whose title agrees best with ours, and its first edition that has an ISBN. With `$surname`, only a work
     * by someone whose name holds a word within a typo or two of it ("Silberschatz" for "Silberchatz").
     */
    private function bestEdition(string $title, array $docs, ?string $surname = null): ?array
    {
        $scored = [];
        foreach ($docs as $doc) {
            if ($surname !== null && ! $this->authorNearlyMatches($surname, $doc['author_name'] ?? [])) {
                continue;
            }
            $score = $this->titleScore($title, (string) ($doc['title'] ?? ''));
            if ($score > 0) {
                $scored[] = [$score, $doc];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($scored as [, $doc]) {
            foreach ($doc['editions']['docs'] ?? [] as $edition) {
                $isbns = array_values(array_filter(array_map(fn ($i) => BookMetadataService::normalizeIsbn((string) $i), $edition['isbn'] ?? [])));
                if ($isbns) {
                    $years = $edition['publish_year'] ?? [];
                    $year = $years ? (int) $years[0] : (preg_match('/(1[5-9]|20)\d{2}/', (string) ($edition['publish_date'][0] ?? ''), $m) ? (int) $m[0] : null);

                    return [
                        'isbn'      => $isbns[0],
                        'title'     => (string) ($edition['title'] ?? $doc['title']),
                        'authors'   => array_values($doc['author_name'] ?? []),
                        'publisher' => $edition['publisher'][0] ?? null,
                        'year'      => $year,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * How well two titles agree, 0 when they don't: the share of words they have in common. "Biology" and "Biology"
     * is 1; "Biology" and "Human Biology" is 0.5 (still a match, but ranked below the exact one); a title that holds
     * the whole of a two-word-or-longer other one ("Differential Calculus" in "Differential Calculus: A Work Text")
     * also counts.
     */
    private function titleScore(string $a, string $b): float
    {
        $words = fn (string $t) => array_values(array_unique(array_diff(
            array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Str::ascii($t))) ?: [], fn ($w) => $w !== ''),
            ['the', 'a', 'an', 'and', 'of', 'to', 'in', 'for', 'on', 'with', 'edition', 'ed'],
        )));
        $x = $words($a);
        $y = $words($b);
        if (! $x || ! $y) {
            return 0.0;
        }
        $common = count(array_intersect($x, $y));
        $jaccard = $common / count(array_unique([...$x, ...$y]));
        $shorter = min(count($x), count($y));
        $contains = $shorter >= 2 && $common === $shorter;

        return $jaccard >= 0.5 || $contains ? max($jaccard, $contains ? 0.5 : 0) : 0.0;
    }

    /**
     * "Biology" and "Biology: Florida Advanced Placement edition" are the same book: the catalogue adds a subtitle the
     * record leaves out. Only for deciding whether a found book agrees with the record, never for searching, where a
     * one-word title must not match every title that holds the word.
     */
    private function sameMainTitle(string $a, string $b): bool
    {
        $main = fn (string $t) => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(Str::ascii(preg_split('/[:;(]/', $t)[0]))));
        $x = $main($a);

        return $x !== '' && $x === $main($b);
    }

    /** @param array<int, string> $names */
    private function authorNearlyMatches(string $surname, array $names): bool
    {
        $target = mb_strtolower(Str::ascii($surname));
        if (mb_strlen($target) < 3) {
            return false;
        }
        $allowed = max(1, intdiv(strlen($target), 5));
        foreach ($names as $name) {
            foreach (preg_split('/[^a-z]+/', mb_strtolower(Str::ascii($name))) ?: [] as $word) {
                if ($word !== '' && levenshtein($word, $target) <= $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    private function surnameOf(string $name): string
    {
        // "Mader, Sylvia S." -> Mader; "Alex Rios" -> Rios.
        $name = trim($name);
        $parts = preg_split('/\s+/', $name) ?: [''];
        $surname = str_contains($name, ',') ? explode(',', $name)[0] : end($parts);

        return trim((string) preg_replace('/[^\p{L}\-\' ]/u', '', $surname));
    }

    /* ---------------------------------------------------------------- saving what was accepted */

    /**
     * Saves what the librarian accepted. Each value fills only a field that is still empty (a book edited meanwhile
     * keeps what was typed), an ISBN another book has is refused, and every book in `$checked` is marked checked so
     * it isn't offered again (left unticked or found nothing: it is edited by hand from now on).
     *
     * @param  array<int, array{book: string, fills: array<string, mixed>}>  $items
     * @param  array<int, string>  $checked
     * @return array{updated: int, checked: int, refusedIsbns: array<int, string>}
     */
    public function apply(array $items, array $checked): array
    {
        $updated = 0;
        $refused = [];

        foreach ($items as $item) {
            $book = Book::with('authors')->where('uuid', $item['book'] ?? '')->first();
            if (! $book) {
                continue;
            }

            $changed = DB::transaction(function () use ($book, $item, &$refused) {
                $fills = $item['fills'] ?? [];
                $labels = [];

                $clean = $this->cleanFills($fills);
                if (isset($clean['isbn'])) {
                    $other = $this->catalog->findBookByIsbn($clean['isbn']);
                    if ($other && $other['bookUuid'] !== $book->uuid) {
                        $refused[] = $book->title;
                        unset($clean['isbn']);
                    }
                }

                $names = ['isbn' => 'ISBN', 'publisher' => 'Publisher', 'publicationYear' => 'Year', 'pageCount' => 'Pages',
                    'edition' => 'Edition', 'volume' => 'Volume', 'coverImageURL' => 'Cover'];
                foreach ($clean as $field => $value) {
                    if ($field !== 'authors' && ($book->{$field} === null || $book->{$field} === '')) {
                        $book->{$field} = $value;
                        $labels[] = $names[$field];
                    }
                }

                if (! empty($clean['authors']) && $book->authors->isEmpty()) {
                    foreach ($clean['authors'] as $name) {
                        $author = Author::firstOrCreate(['name' => $name], ['uuid' => Str::uuid()]);
                        $book->authors()->attach($author->authorID, ['uuid' => Str::uuid(), 'role' => null]);
                    }
                    $labels[] = 'Author';
                }

                $book->detailsCheckedAt = now();
                $book->save();

                if ($labels) {
                    $this->stockLog->bookEdited($book, 'Filled in from the online catalogues: '.implode(', ', $labels));
                }

                // A Dewey number: one the catalogue found, or one the librarian typed for a book nothing was found for.
                $dewey = $fills['deweyNumber'] ?? null;
                if (is_string($dewey) && preg_match('/^\d{3}(\.\d{1,10})?$/', trim($dewey)) && ($book->deweyNumber === null || $book->deweyStatus === 'needs_review')) {
                    $source = in_array($fills['deweySource'] ?? null, ['library_of_congress', 'open_library', 'manual'], true) ? $fills['deweySource'] : 'manual';
                    $this->catalog->applyDewey($book->fresh(), trim($dewey), $source);
                    $labels[] = 'Dewey';
                }

                return $labels !== [];
            });

            $updated += $changed ? 1 : 0;
        }

        $marked = Book::whereIn('uuid', $checked)->whereNull('detailsCheckedAt')->update(['detailsCheckedAt' => now()]);

        return ['updated' => $updated, 'checked' => $marked + count($items), 'refusedIsbns' => $refused];
    }

    /** The accepted values, each checked again as the book form would check it; anything off is dropped. */
    private function cleanFills(array $fills): array
    {
        $clean = [];
        if (isset($fills['isbn']) && ($isbn = BookMetadataService::normalizeIsbn((string) $fills['isbn']))) {
            $clean['isbn'] = $isbn;
        }
        foreach (['publisher' => 255, 'edition' => 50, 'volume' => 50] as $field => $max) {
            if (isset($fills[$field]) && is_string($fills[$field]) && ($text = trim($fills[$field])) !== '') {
                $clean[$field] = mb_substr($text, 0, $max);
            }
        }
        if (isset($fills['publicationYear']) && is_numeric($fills['publicationYear'])) {
            $year = (int) $fills['publicationYear'];
            if ($year >= 1000 && $year <= (int) now()->year) {
                $clean['publicationYear'] = $year;
            }
        }
        if (isset($fills['pageCount']) && is_numeric($fills['pageCount']) && (int) $fills['pageCount'] > 0) {
            $clean['pageCount'] = (int) $fills['pageCount'];
        }
        if (isset($fills['coverImageURL']) && is_string($fills['coverImageURL']) && str_starts_with($fills['coverImageURL'], 'https://') && strlen($fills['coverImageURL']) <= 255) {
            $clean['coverImageURL'] = $fills['coverImageURL'];
        }
        if (isset($fills['authors']) && is_array($fills['authors'])) {
            $clean['authors'] = array_values(array_filter(array_map(fn ($n) => is_string($n) ? mb_substr(trim($n), 0, 255) : '', $fills['authors'])));
        }

        return $clean;
    }
}
