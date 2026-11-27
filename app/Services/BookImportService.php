<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Brings the library's accession record (an Excel sheet, one row per copy) into the catalog. The browser reads the
 * sheet and sends its rows; everything about what a row means is decided here:
 *  - Class "Fil.515.3 AS843 2023" is the area (Fil = Filipiniana), the Dewey number (515.3) and the Cutter (AS843);
 *  - copies of the same title (same title, author, edition and area) become one book with several copies;
 *  - the accession number in the record is kept, so it matches the number stamped in the book;
 *  - details the record doesn't have stay empty, and the book shows as incomplete until someone fills them in.
 * A row is skipped, with the reason, when it can't be brought in safely: no title, or an accession number that the
 * file uses twice or that the catalog already has. The same rows give the same answer, so a check (`dryRun`) shows
 * exactly what the import will do, and importing the file again only adds what is new.
 */
class BookImportService
{
    /** Class prefixes as the library writes them, and the area each one means. Longest first, so "Gen.Ref" wins over "Ref". */
    // A prefix ends where a letter doesn't follow, or at "INC" ("CirINC617.075", "Gen.RefINC610.030"), since the
    // record often has no space or dot between the area and the number.
    private const AREA_PREFIXES = [
        ['/^ge?n?\.?\s*ref(?=inc|[^a-z]|$)/i', 'general-reference'],
        ['/^6m\.?\s*ref(?=inc|[^a-z]|$)/i', 'reserved'],
        ['/^textbook(?=[^a-z]|$)/i', 'circulation'],
        ['/^(fil|fik|fril|filc)(?=inc|[^a-z]|$)/i', 'filipiniana'],
        ['/^cir(?=inc|[^a-z]|$)/i', 'circulation'],
        ['/^(ref|res)(?=inc|[^a-z]|$)/i', 'reserved'],
        ['/^(fic|fio)(?=[^a-z]|$)/i', 'fiction'],
        ['/^tb(?=[^a-z]|$)/i', 'circulation'],
    ];

    /** Where a row with no area of its own goes (the library's main lending area). */
    private const DEFAULT_AREA = 'circulation';

    /** @var array<string, Book|null> */
    private array $bookCache = [];

    public function __construct(private CatalogService $catalog)
    {
    }

    /**
     * Reads the rows and either reports what an import would do (`$dryRun`) or does it.
     *
     * @param  array<int, array<string, mixed>>  $rows  each: row (sheet row number), accessionNumber, dateReceived,
     *     callNumber (the Class column), area, authors, title, edition, volume, pages, sourceOfFund, cost, publisher,
     *     year, remarks, isbn. Only `title` is needed.
     * @return array{books: int, existingBooks: int, copies: int, emptyRows: int, skipped: array<int, array{row: ?int, accessionNumber: ?int, title: string, reason: string}>, notes: array<string, int>}
     */
    public function import(array $rows, bool $dryRun, string $fileName = 'the accession record'): array
    {
        $notes = ['incomplete' => 0, 'noArea' => 0, 'unreadDate' => 0, 'unreadCallNumber' => 0, 'newNumbers' => 0];
        $skipped = [];
        $emptyRows = 0;

        $parsed = [];
        foreach ($rows as $raw) {
            $row = $this->parseRow($raw);
            if ($row['title'] === '') {
                if ($row['hasData']) {
                    $skipped[] = $this->skip($row, 'No title, so there is nothing to catalog.');
                } else {
                    $emptyRows++; // an accession number the record lists but never used
                }
                continue;
            }
            $parsed[] = $row;
        }

        // An accession number belongs to one copy. When the file gives one to several rows, it can't say which is right.
        $uses = array_count_values(array_filter(array_map(fn ($r) => $r['accessionNumber'], $parsed), fn ($n) => $n !== null));
        $inCatalog = BookCopy::whereIn('accessionNumber', array_keys($uses))->pluck('accessionNumber')->map(fn ($n) => (int) $n)->flip();

        $groups = [];
        foreach ($parsed as $row) {
            $number = $row['accessionNumber'];
            if ($number !== null && $uses[$number] > 1) {
                $skipped[] = $this->skip($row, "Accession no. {$number} is used by {$uses[$number]} rows in the file. Check the books and add this one by hand.");
                continue;
            }
            if ($number !== null && isset($inCatalog[$number])) {
                $skipped[] = $this->skip($row, "Accession no. {$number} is already in the catalog.");
                continue;
            }

            $key = $this->titleKey($row);
            $groups[$key] ??= ['rows' => [], 'key' => $key];
            $groups[$key]['rows'][] = $row;
        }

        $books = $existingBooks = $copies = 0;
        foreach ($groups as $group) {
            $book = $this->mergeBook($group['rows']);
            $existing = $this->findExisting($book);

            // A copy with no accession number can't be told apart from one an earlier import already brought in, so
            // once its title is in the catalog it is left out: importing the same file twice never doubles it.
            $rows = [];
            foreach ($group['rows'] as $row) {
                if ($row['accessionNumber'] === null && $existing) {
                    $skipped[] = $this->skip($row, 'No accession number, and this title is already in the catalog. If it is another copy, add it with Add Book Copy.');
                } else {
                    $rows[] = $row;
                }
            }
            if ($rows === []) {
                continue;
            }

            $existing ? $existingBooks++ : $books++;

            if (! $existing && $this->isIncomplete($book)) {
                $notes['incomplete']++;
            }

            $copyRows = [];
            foreach ($rows as $row) {
                $notes['noArea'] += $row['area'] === null ? 1 : 0;
                $notes['unreadDate'] += $row['dateUnread'] ? 1 : 0;
                $notes['unreadCallNumber'] += $row['callNumberUnread'] ? 1 : 0;
                $notes['newNumbers'] += $row['accessionNumber'] === null ? 1 : 0;
                $copyRows[] = [
                    'accessionNumber' => $row['accessionNumber'],
                    'dateReceived'    => $row['dateReceived'],
                    'sourceOfFund'    => $row['sourceOfFund'],
                    'cost'            => $row['cost'],
                    'remarks'         => $row['copyRemarks'],
                    'status'          => $row['discarded'] ? 'retired' : 'available',
                ];
            }
            $copies += count($copyRows);

            if (! $dryRun) {
                $saved = $this->catalog->importBook($book, $copyRows, $existing, "Imported from {$fileName}");
                // Later rows of the same title (in this request or the next one) join this book.
                $this->bookCache[$this->bookKey($book)] = $saved;
            }
        }

        return [
            'books'         => $books,
            'existingBooks' => $existingBooks,
            'copies'        => $copies,
            'emptyRows'     => $emptyRows,
            'skipped'       => $skipped,
            'notes'         => $notes,
        ];
    }

    /* ---------------------------------------------------------------- reading a row */

    /** @param array<string, mixed> $raw */
    private function parseRow(array $raw): array
    {
        $text = fn (string $key) => trim((string) preg_replace('/\s+/u', ' ', (string) ($raw[$key] ?? '')));

        [$area, $dewey, $cutter, $callUnread] = $this->parseClass($text('callNumber'));
        // An explicit Area column (EntryPoint's own export has one) beats the Class prefix.
        $area = $this->areaFromName($text('area')) ?? $area;

        [$isbn, $remarks] = $this->isbnFromRemarks($text('isbn') ?: null, $text('remarks'));
        // A date that only exists because Excel counted up when a cell was dragged down ("03-12,05", "03-12,06",
        // "03-12,07"...) is not a real date; the browser marks those (it sees the rows in order) and they aren't read.
        [$date, $dateUnread] = ! empty($raw['dateAutofilled']) && $text('dateReceived') !== ''
            ? [null, true]
            : $this->parseDate($text('dateReceived'));

        $copyRemarks = $remarks;
        if ($dateUnread) {
            $copyRemarks = trim("Date received in the record: {$text('dateReceived')}. ".$copyRemarks);
        }

        $volume = $text('volume');
        // "c-1", "C-2" in the Volumes column is the copy number, not a volume.
        if (preg_match('/^c\s*-?\s*\d+$/i', $volume)) {
            $volume = '';
        }

        $title = $text('title');
        $hasData = $title !== '' || collect(['dateReceived', 'callNumber', 'authors', 'edition', 'pages', 'sourceOfFund', 'cost', 'publisher', 'year', 'remarks', 'isbn'])
            ->contains(fn ($k) => $text($k) !== '');

        return [
            'row'              => isset($raw['row']) ? (int) $raw['row'] : null,
            'accessionNumber'  => $this->positiveInt($raw['accessionNumber'] ?? null),
            'title'            => $title,
            'hasData'          => $hasData,
            'authors'          => $this->splitAuthors($text('authors')),
            'area'             => $area,
            'deweyNumber'      => $dewey,
            'cutter'           => $cutter,
            'callNumberUnread' => $callUnread,
            'rawCallNumber'    => $text('callNumber'),
            'isbn'             => $isbn,
            'edition'          => $text('edition') ?: null,
            'volume'           => $volume ?: null,
            'pageCount'        => $this->positiveInt($raw['pages'] ?? null),
            'publisher'        => $text('publisher') ?: null,
            'publicationYear'  => $this->year($raw['year'] ?? null),
            'sourceOfFund'     => $this->fund($text('sourceOfFund')),
            'cost'             => $this->money($raw['cost'] ?? null),
            'dateReceived'     => $date,
            'dateUnread'       => $dateUnread,
            'copyRemarks'      => $copyRemarks !== '' && ! preg_match('/^discarded\.?$/i', $remarks) ? $copyRemarks : null,
            'discarded'        => (bool) preg_match('/^discarded\.?$/i', $remarks),
        ];
    }

    /**
     * "Fil.515.3 AS843 2023" -> [filipiniana, "515.3", "AS843"]. "Cir616.1C112007" -> [circulation, "616.1", "C11"]
     * (a year typed straight after the Cutter is dropped). A class with no three-digit Dewey number gives no call
     * number, and the 4th value says so, so the book waits for one instead of getting a wrong one.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: bool}
     */
    public function parseClass(string $class): array
    {
        $rest = trim($class);
        if ($rest === '') {
            return [null, null, null, false];
        }

        $area = null;
        foreach (self::AREA_PREFIXES as [$pattern, $key]) {
            if (preg_match($pattern, $rest, $m)) {
                $area = $key;
                $rest = ltrim(substr($rest, strlen($m[0])), ' .,');
                break;
            }
        }
        // "INC" sometimes follows the area ("Cir INC 174.2 R86"); it isn't part of the number.
        $rest = ltrim((string) preg_replace('/^inc(?=[^a-z]|$)/i', '', $rest), ' .,');

        // "621.3192", "621,3192" or "621. 3192" (a space typed after the point). Fiction is shelved by the author mark
        // alone ("Fic. D69s 1997"): no Dewey number, just the Cutter, which is kept.
        $dewey = null;
        $after = $rest;
        if (preg_match('/^(\d{3})(?:[.,]\s?(\d{1,10}))?(?!\d)/', $rest, $m)) {
            $dewey = $m[1].(isset($m[2]) && $m[2] !== '' ? '.'.$m[2] : '');
            $after = ltrim(substr($rest, strlen($m[0])), ' .,');
        }

        $cutter = null;
        if (preg_match('/^([A-Za-z]{1,3})(\d+)([A-Za-z]?)/', $after, $c)) {
            $digits = $c[2];
            // "M1152010": the Cutter M115 with the year 2010 typed onto it.
            if (strlen($digits) > 4 && preg_match('/^(\d+?)((?:19|20)\d{2})$/', $digits, $y)) {
                $digits = $y[1];
                $c[3] = '';
            }
            $cutter = $c[1].$digits.$c[3];
        }

        // Unread only when neither a Dewey number nor an author mark could be found.
        return [$area, $dewey, $cutter, $dewey === null && $cutter === null];
    }

    /**
     * "03-12,05" (month-day,year), "03/19/2026", "3/19/26", or a real Excel date (sent as 2026-03-19). Anything else,
     * or an impossible date, is not guessed at: the date is left out and the row says so.
     *
     * @return array{0: ?string, 1: bool} the date as Y-m-d, and whether there was text that couldn't be read
     */
    public function parseDate(string $text): array
    {
        if ($text === '') {
            return [null, false];
        }

        $patterns = [
            '/^(\d{4})-(\d{1,2})-(\d{1,2})/' => [2, 3, 1],
            '/^(\d{1,2})\s*[\/-]\s*(\d{1,2})\s*[\/.,-]\s*-?\s*(\d{2}|\d{4})$/' => [1, 2, 3],
        ];
        foreach ($patterns as $pattern => [$mi, $di, $yi]) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }
            [$month, $day, $year] = [(int) $m[$mi], (int) $m[$di], (int) $m[$yi]];
            if ($year < 100) {
                $year += $year <= (int) now()->format('y') ? 2000 : 1900;
            }
            if (checkdate($month, $day, $year) && $year >= 1950 && Carbon::create($year, $month, $day)->lte(now())) {
                return [sprintf('%04d-%02d-%02d', $year, $month, $day), false];
            }
        }

        return [null, true];
    }

    /** "Lee , Sergio L." -> ["Lee, Sergio L."]; several authors may be split with ";" or " / ". */
    private function splitAuthors(string $text): array
    {
        $names = preg_split('/\s*(?:;|\s\/\s)\s*/', $text) ?: [];

        return array_values(array_filter(array_map(
            fn ($name) => trim((string) preg_replace(['/\s+,/', '/,(?=\S)/'], [',', ', '], trim($name)), ' ,'),
            $names,
        ), fn ($name) => $name !== ''));
    }

    /**
     * Pulls an ISBN out of the remarks ("ISBN: 978-621-427-126-9"). A valid one becomes the book's ISBN and leaves
     * the remarks; one that doesn't check out stays in the remarks as it was typed.
     *
     * @return array{0: ?string, 1: string}
     */
    private function isbnFromRemarks(?string $isbn, string $remarks): array
    {
        $valid = fn (?string $candidate) => $candidate !== null && $candidate !== '' && $this->isValidIsbn($candidate);

        if ($valid($isbn)) {
            return [$this->cleanIsbn($isbn), $remarks];
        }

        if (preg_match('/ISBN(?:-1[03])?\s*:?\s*([0-9Xx][0-9Xx\-\s]{8,20}[0-9Xx])/i', $remarks, $m) && $valid($m[1])) {
            $rest = trim(str_replace($m[0], '', $remarks), " /;,.");

            return [$this->cleanIsbn($m[1]), $rest];
        }

        return [null, $remarks];
    }

    private function cleanIsbn(string $isbn): string
    {
        return strtoupper((string) preg_replace('/[^0-9Xx]/', '', $isbn));
    }

    private function isValidIsbn(string $isbn): bool
    {
        // The same check as the book form (ValidIsbn): 10 or 13 digits with a correct check digit.
        return BookMetadataService::normalizeIsbn($isbn) !== null;
    }

    /** "Library funds", "libarary fund", "library found"... are one source; "donation" is Donated. */
    private function fund(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        if (FundSourceService::isDonated($text)) {
            return 'Donated';
        }
        if (preg_match('/^li\w*ra\w*\s*(fund|found|food|fee)s?$|^library$/i', $text)) {
            return 'Library Fund';
        }

        return $text;
    }

    private function areaFromName(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        $key = Str::slug($name);
        $areas = \App\Models\LoanPeriod::pluck('name', 'area');
        foreach ($areas as $area => $label) {
            if ($key === $area || $key === Str::slug($label)) {
                return $area;
            }
        }

        return $this->parseClass($name)[0];
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) && (int) $value > 0 && (float) $value == (int) $value ? (int) $value : null;
    }

    private function year(mixed $value): ?int
    {
        $year = $this->positiveInt($value);

        return $year !== null && $year >= 1000 && $year <= (int) now()->year ? $year : null;
    }

    private function money(mixed $value): ?float
    {
        $value = is_string($value) ? str_replace([',', '₱', 'P', ' '], '', trim($value)) : $value;

        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 2) : null;
    }

    /* ---------------------------------------------------------------- titles */

    private function norm(?string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $text))));
    }

    /** Copies of one title: the same title, first author, edition and area. */
    private function titleKey(array $row): string
    {
        return implode('|', [$this->norm($row['title']), $this->norm($row['authors'][0] ?? ''), $this->norm($row['edition']), $row['area'] ?? self::DEFAULT_AREA]);
    }

    private function bookKey(array $book): string
    {
        return implode('|', [$this->norm($book['title']), $this->norm($book['authors'][0] ?? ''), $this->norm($book['edition']), $book['areaOfLibrary']]);
    }

    /** The book's record from all its rows: the first row that has each detail gives it. */
    private function mergeBook(array $rows): array
    {
        $first = fn (string $field) => collect($rows)->pluck($field)->first(fn ($v) => $v !== null && $v !== '' && $v !== []);

        // Without a Dewey number the record's class is kept word for word, so nothing in it is lost.
        $remarks = null;
        $raw = $first('rawCallNumber');
        if ($raw && ! $first('deweyNumber')) {
            $remarks = "Call number in the accession record: {$raw}";
        }
        $withDewey = collect($rows)->first(fn ($r) => $r['deweyNumber'] !== null);

        return [
            'title'           => $rows[0]['title'],
            'authors'         => $first('authors') ?? [],
            'areaOfLibrary'   => $rows[0]['area'] ?? self::DEFAULT_AREA,
            'deweyNumber'     => $first('deweyNumber'),
            // The Cutter that goes with the Dewey number, or, for fiction and the like, the author mark on its own.
            'cutter'          => $withDewey ? $withDewey['cutter'] : $first('cutter'),
            'isbn'            => $first('isbn'),
            'publicationYear' => $first('publicationYear'),
            'edition'         => $rows[0]['edition'],
            'volume'          => $first('volume'),
            'pageCount'       => $first('pageCount'),
            'publisher'       => $first('publisher'),
            'sourceOfFund'    => $first('sourceOfFund'),
            'cost'            => $first('cost'),
            'remarks'         => $remarks,
        ];
    }

    /** The same title already in the catalog (this import's earlier rows included), whose copies these become. */
    private function findExisting(array $book): ?Book
    {
        $key = $this->bookKey($book);
        if (array_key_exists($key, $this->bookCache)) {
            return $this->bookCache[$key];
        }

        $candidates = Book::with('authors')->where('areaOfLibrary', $book['areaOfLibrary'])
            ->whereRaw('LOWER(title) = ?', [Str::lower($book['title'])])->get();

        return $this->bookCache[$key] = $candidates->first(fn (Book $b) => $this->bookKey([
            'title'         => $b->title,
            'authors'       => $b->authors->pluck('name')->all(),
            'edition'       => $b->edition,
            'areaOfLibrary' => $b->areaOfLibrary,
        ]) === $key);
    }

    /** What Add Book requires and the record didn't have. */
    private function isIncomplete(array $book): bool
    {
        foreach (['isbn', 'publicationYear', 'edition', 'volume', 'pageCount', 'publisher', 'sourceOfFund', 'cost'] as $field) {
            if ($book[$field] === null || $book[$field] === '') {
                return true;
            }
        }

        return $book['authors'] === [];
    }

    private function skip(array $row, string $reason): array
    {
        return ['row' => $row['row'], 'accessionNumber' => $row['accessionNumber'], 'title' => $row['title'], 'reason' => $reason];
    }
}
