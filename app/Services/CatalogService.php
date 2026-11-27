<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Repositories\Contracts\AuthorRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    private const CUTTER_TABLE_FIRST_VOWEL = [
        'B-C' => 2, 'D-K' => 3, 'L-M' => 4, 'N-O' => 5,
        'P-Q' => 6, 'R-R' => 7, 'S-T' => 8, 'U-Y' => 9,
    ];

    private const CUTTER_TABLE_FIRST_S = [
        'A-C' => 2, 'D-D' => 3, 'E-G' => 4, 'H-L' => 5,
        'M-S' => 6, 'T-T' => 7, 'U-V' => 8, 'W-Z' => 9,
    ];

    private const CUTTER_TABLE_DEFAULT = [
        'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
        'R-T' => 7, 'U-X' => 8, 'Y-Z' => 9,
    ];

    private const CUTTER_TABLE_QU = [
        'A-D' => 3, 'E-H' => 4, 'I-N' => 5, 'O-Q' => 6,
        'R-S' => 7, 'T-X' => 8, 'Y-Z' => 9,
    ];

    private const CUTTER_EXPANSION_TABLE = [
        'A-D' => 3, 'E-H' => 4, 'I-L' => 5, 'M-O' => 6,
        'P-S' => 7, 'T-V' => 8, 'W-Z' => 9,
    ];

    public function __construct(
        private BookRepositoryInterface $books,
        private BookCopyRepositoryInterface $bookCopies,
        private AuthorRepositoryInterface $authors,
        private BookSubjectRepositoryInterface $subjects,
        private LoanRepositoryInterface $loans,
        private LibraryClassificationService $classification,
        private StockLogService $stockLog,
        private ReservationRepositoryInterface $reservations,
        private PenaltyRepositoryInterface $penalties,
        private NotificationService $notifications,
        private FundSourceService $fundSources,
    ) {
    }

    /**
     * @return array{totalBooks: int, availableBooks: int, borrowedBooks: int, overdueBooks: int}
     */
    public function libraryStats(): array
    {
        $counts = $this->bookCopies->countsByStatus();

        return [
            'totalBooks'     => $this->books->totalCount(),
            'availableBooks' => (int) $counts->get('available', 0),
            'borrowedBooks'  => (int) $counts->get('borrowed', 0),
            'overdueBooks'   => $this->loans->stats()['overdue'],
        ];
    }

    /**
     * Catalog listing for the client's Library page. Field names are
     * remapped to match the frontend's contract (`callNumber` instead of
     * worked out from `deweyNumber` + `cutter`), independent of the storage schema.
     */
    public function catalogIndex(?string $search, ?int $subjectID, ?string $availability, int $perPage, ?string $dewey = null, ?string $area = null): LengthAwarePaginator
    {
        return $this->books->paginateCatalog($search, $subjectID, $availability, $perPage, $dewey, $area)->through(fn (Book $book) => [
            'bookID'        => $book->bookID,
            'areaOfLibrary' => $book->areaOfLibrary,
            'uuid'          => $book->uuid,
            'title'         => $book->title,
            'isbn'          => $book->isbn,
            'callNumber'    => $book->callNumber,
            'deweyStatus'   => $book->deweyStatus,
            // What Add Book would ask for that this record lacks (books from the old accession record): "Incomplete".
            'missingDetails' => $book->missingDetails(),
            'coverImageURL' => $book->coverImageURL,
            'subject'       => $book->subject ? [
                'subjectID' => $book->subject->subjectID,
                'name'      => $book->subject->name,
            ] : null,
            'authors' => $book->authors->map(fn (Author $author) => [
                'authorID' => $author->authorID,
                'name'     => $author->name,
            ])->values(),
            'copies' => $book->copies->map(fn ($copy) => [
                'copyID'          => $copy->copyID,
                'uuid'            => $copy->uuid,
                'accessionNumber' => $copy->accessionNumber,
                'status'          => $copy->status,
            ])->values(),
            'heldCopies' => (int) ($book->held_copies ?? 0),
        ]);
    }

    /**
     * The Book Catalog tab: one row per copy, each with its own accession number and status next to
     * the fuller book record (publisher, edition, volume, year, pages, area of the library, fund, cost...).
     */
    public function copyCatalog(?string $search, ?int $subjectID, ?string $status, ?string $area, int $perPage): LengthAwarePaginator
    {
        return $this->bookCopies->paginateCopyCatalog($search, $subjectID, $status, $area, $perPage)->through(fn ($copy) => [
            'copyID'          => $copy->copyID,
            'uuid'            => $copy->uuid,
            'accessionNumber' => $copy->accessionNumber,
            'barcodeValue'    => $copy->barcodeValue,
            'status'          => $copy->status,
            // This copy's own record of where it came from (null for copies that predate it).
            'sourceOfFund'    => $copy->sourceOfFund,
            'cost'            => $copy->cost,
            'remarks'         => $copy->remarks,
            // The day it reached the library, from the accession record (null when the record has no readable date).
            'dateReceived'    => $copy->dateReceived?->toDateString(),
            'book'            => [
                ...$copy->book->only([
                    'bookID', 'uuid', 'title', 'isbn', 'areaOfLibrary', 'publicationYear', 'volume', 'edition', 'pageCount',
                    'publisher', 'sourceOfFund', 'cost', 'remarks', 'shelfLocation', 'coverImageURL',
                ]),
                'callNumber'  => $copy->book->callNumber,
                'deweyStatus' => $copy->book->deweyStatus,
                'subject'    => $copy->book->subject?->only(['subjectID', 'name']),
                'authors'    => $copy->book->authors->pluck('name')->values(),
            ],
        ]);
    }

    /**
     * Changes one copy's status from the Book Catalog: back on the shelf, damaged, lost, or retired
     * (removed from the catalog; its accession number is not reused). A copy out on loan changes only
     * through check-in, so it can't be edited here.
     */
    public function updateCopyStatus(BookCopy $copy, string $status): BookCopy
    {
        if ($copy->status === 'borrowed') {
            throw ValidationException::withMessages([
                'status' => ["Accession no. {$copy->accessionNumber} is out on loan. Check it in first."],
            ]);
        }

        if ($copy->status === 'retired') {
            throw ValidationException::withMessages([
                'status' => ["Accession no. {$copy->accessionNumber} has already been removed from the catalog."],
            ]);
        }

        // A copy with an unpaid penalty stays as it is until the student pays or replaces it. The one way out is
        // finding or repairing it, which cancels the penalty (the student no longer owes for a book that is back).
        $open = $this->penalties->openForCopy($copy->copyID);

        if ($open && $status !== 'available') {
            throw ValidationException::withMessages([
                'status' => ["Accession no. {$copy->accessionNumber} has an unpaid penalty. Settle it first, or mark the copy found or repaired to cancel it."],
            ]);
        }

        if ($status === 'retired') {
            return $this->retireCopy($copy, 'Removed');
        }

        return DB::transaction(function () use ($copy, $status, $open) {
            $before = $copy->status;
            $open?->delete();

            $updated = $this->bookCopies->update($copy, ['status' => $status]);
            $this->stockLog->statusChanged($copy->book ?? $copy->load('book')->book, $updated, $before, $status);

            return $updated;
        });
    }

    /** The most labels one print will gather; more than this and the librarian is told to narrow it down. */
    public const MAX_LABELS = 500;

    /**
     * What a barcode label for each copy needs: the code that gets scanned, the accession number, and enough of the
     * book to tell it apart on the shelf. Chosen by copy uuids, by one book, or by the Book Catalog's own filters.
     *
     * Every criterion narrows the set, so "this book's copies numbered 1-20" works; `accessions` is a list of
     * ['from' => n, 'to' => m] ranges. `total` is the number found, capped just past the limit (so "501" means "more
     * than 500").
     *
     * @param  array{copies?: array<int, string>, book?: string, search?: ?string, subjectID?: ?int, status?: ?string, area?: ?string, accessions?: array<int, array{from: int, to: int}>}  $selection
     * @return array{labels: array<int, array<string, mixed>>, total: int, truncated: bool}
     */
    public function copyLabels(array $selection): array
    {
        $copies = $this->bookCopies->forLabels($selection, self::MAX_LABELS + 1);
        $total = $copies->count();

        // Every label prints its Dewey number and Cutter, verified or not. `unconfirmed` marks the books whose Dewey number is
        // still only a guess from the category, so the print window can list those copies and ask before they are printed.
        $labels = $copies->take(self::MAX_LABELS)->map(function (BookCopy $copy) {
            // The label's lines, top to bottom: area of the library, call number (the Dewey number), the author's
            // Cutter, year of publication.
            $spine = $copy->book->spineLabel();

            return [
                'uuid'            => $copy->uuid,
                'accessionNumber' => $copy->accessionNumber,
                'barcodeValue'    => $copy->barcodeValue,
                'title'           => $copy->book->title,
                'authors'         => $copy->book->authors->pluck('name')->values(),
                // The two parts of the shelf number, apart (the label prints them on their own lines) ...
                'dewey'           => $copy->book->deweyNumber ?: null,
                'cutter'          => $copy->book->cutter ?: null,
                // ... and joined.
                'callNumber'      => $copy->book->callNumber,
                'unconfirmed'     => $spine['pending'],
                'area'            => $spine['area'],
                'year'            => $copy->book->publicationYear,
            ];
        })->values()->all();

        return ['labels' => $labels, 'total' => $total, 'truncated' => $total > self::MAX_LABELS];
    }

    /**
     * The checkout desk's first step: the librarian scans the book, and this says which copy it is and whether it
     * can go out. A copy that can't (on loan, damaged, lost) is still returned, with the reason in `lendable`.
     *
     * @return array<string, mixed>
     */
    public function findCopyByScanCode(string $code): array
    {
        $copy = $this->bookCopies->findByScanCode($code);

        if (! $copy) {
            throw ValidationException::withMessages(['code' => ['No copy in the catalog has that barcode.']]);
        }

        $lendable = match ($copy->status) {
            'available' => null,
            'borrowed'  => 'This copy is already out on loan.',
            'damaged'   => 'This copy is marked damaged and cannot be lent.',
            'lost'      => 'This copy is marked lost and cannot be lent.',
            default     => 'This copy cannot be lent right now.',
        };

        // A shelf copy may still be spoken for: every available copy of the title is held for accepted reservations.
        $heldForReservation = false;
        if ($copy->status === 'available'
            && $this->bookCopies->availableCountForBook($copy->bookID) <= $this->reservations->acceptedCountForBook($copy->bookID)) {
            $lendable = 'Every available copy of this book is held for an accepted reservation.';
            $heldForReservation = true;
        }

        return [
            'copyID'          => $copy->copyID,
            'uuid'            => $copy->uuid,
            'accessionNumber' => $copy->accessionNumber,
            'barcodeValue'    => $copy->barcodeValue,
            'status'          => $copy->status,
            'lendable'        => $lendable,
            // Only held for a reservation: the checkout of that reservation (its slip) may still lend it, and the
            // server's checkout allows it (CirculationService::checkout with a reservationID).
            'heldForReservation' => $heldForReservation,
            'book'            => [
                'bookID'        => $copy->book->bookID,
                'uuid'          => $copy->book->uuid,
                'title'         => $copy->book->title,
                'coverImageURL' => $copy->book->coverImageURL,
                'authors'       => $copy->book->authors->pluck('name')->values(),
                // What the librarian needs to be sure it is the right book in their hand.
                'callNumber'      => $copy->book->callNumber,
                'isbn'            => $copy->book->isbn,
                'publisher'       => $copy->book->publisher,
                'publicationYear' => $copy->book->publicationYear,
                'edition'         => $copy->book->edition,
                'volume'          => $copy->book->volume,
                'areaOfLibrary'   => $copy->book->areaOfLibrary,
                'shelfLocation'   => $copy->book->shelfLocation,
            ],
        ];
    }

    /**
     * Corrects one copy's own record: where it came from, what it cost, its remarks. Unlike the status this is
     * allowed while the copy is out on loan (it changes nothing about the loan), but not once it has been removed.
     *
     * @param  array{sourceOfFund?: string, cost?: float|int|string, remarks?: string|null}  $details
     */
    public function updateCopyDetails(BookCopy $copy, array $details): BookCopy
    {
        if ($copy->status === 'retired') {
            throw ValidationException::withMessages([
                'status' => ["Accession no. {$copy->accessionNumber} has already been removed from the catalog."],
            ]);
        }

        if (array_key_exists('sourceOfFund', $details)) {
            $details['sourceOfFund'] = $this->fundSources->resolve($details['sourceOfFund']);
        }
        if (array_key_exists('remarks', $details)) {
            $details['remarks'] = trim((string) $details['remarks']) !== '' ? trim($details['remarks']) : null;
        }

        return DB::transaction(function () use ($copy, $details) {
            $copy->loadMissing('book');

            // Only what actually changed is saved and logged ("Cost: ₱350.00 → ₱400.00").
            $changes = [];
            foreach ($details as $field => $value) {
                $before = $copy->{$field};
                $same = $field === 'cost' ? (float) $before === (float) $value : (string) $before === (string) $value;
                if ($same) {
                    unset($details[$field]);
                    continue;
                }

                $changes[] = match ($field) {
                    'cost'         => 'Cost: '.$this->peso($before).' → '.$this->peso($value),
                    'sourceOfFund' => 'Source of fund: '.($before ?: '—').' → '.($value ?: '—'),
                    'remarks'      => 'Remarks: '.($before ?: '—').' → '.($value ?: '—'),
                };
            }

            if (! $details) {
                return $copy;
            }

            $updated = $this->bookCopies->update($copy, $details);
            $this->stockLog->edited($copy->book, $updated, implode('; ', $changes));

            return $updated;
        });
    }

    /** The parts of a book's record the stock log keeps an eye on, in the words the librarian reads them. */
    private function trackedBookFields(Book $book): array
    {
        $book->loadMissing(['subject', 'authors']);

        return [
            'Title'              => $book->title,
            'Category'           => $book->subject?->name,
            'Area of the library' => $book->libraryArea?->name ?? $book->areaOfLibrary,
            'ISBN'               => $book->isbn,
            'Authors'            => $book->authors->pluck('name')->sort()->join(', '),
            'Publisher'          => $book->publisher,
            'Year published'     => $book->publicationYear,
            'Edition'            => $book->edition,
            'Volume'             => $book->volume,
            'Pages'              => $book->pageCount,
            'Shelf location'     => $book->shelfLocation,
            'Cost'               => $book->cost !== null ? $this->peso($book->cost) : null,
            'Source of fund'     => $book->sourceOfFund,
            'Remarks'            => $book->remarks,
            'New arrival'        => $book->isNewArrival ? 'Yes' : 'No',
            'Cover photo'        => $book->coverImageURL ? 'Set' : null,
        ];
    }

    /** @return array<int, string> "Field: before → after" for each field that is different. */
    private function describeBookChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $label => $now) {
            $was = $before[$label] ?? null;
            if ((string) $was === (string) $now) {
                continue;
            }
            $changes[] = $label === 'Cover photo'
                ? 'Cover photo '.($now ? 'changed' : 'removed')
                : $label.': '.(($was === null || $was === '') ? '—' : $was).' → '.(($now === null || $now === '') ? '—' : $now);
        }

        return $changes;
    }

    private function peso(mixed $amount): string
    {
        return $amount === null || $amount === '' ? '—' : '₱'.number_format((float) $amount, 2);
    }

    /**
     * Adds `$quantity` more copies of a book that is already in the catalog, each with the next accession
     * number, and logs every one in the stock log. `$acquisition` is where they came from: sourceOfFund,
     * cost (of each copy) and optional remarks, kept on every copy; the remarks also go into the stock log.
     *
     * @param  array{sourceOfFund?: string, cost?: float|int|string, remarks?: string|null}  $acquisition
     * @return Collection<int, BookCopy>
     */
    public function addCopies(Book $book, int $quantity, array $acquisition = []): Collection
    {
        return DB::transaction(function () use ($book, $quantity, $acquisition) {
            $copies = [];

            for ($i = 0; $i < $quantity; $i++) {
                $copies[] = $this->createCopy($book, 'Added copy', $acquisition['remarks'] ?? null, $acquisition);
            }

            return new Collection($copies);
        });
    }

    /** Takes one copy out of the catalog (retires it, so its accession number is never reused) and logs why. */
    public function removeCopy(BookCopy $copy, string $reason, ?string $note = null): BookCopy
    {
        return $this->retireCopy($copy, $reason, $note);
    }

    private function retireCopy(BookCopy $copy, string $reason, ?string $note = null): BookCopy
    {
        return DB::transaction(function () use ($copy, $reason, $note) {
            $copy = $this->bookCopies->lockForUpdateWithBook($copy->copyID);

            if ($copy->status === 'borrowed') {
                throw ValidationException::withMessages([
                    'status' => ["Accession no. {$copy->accessionNumber} is out on loan. Check it in first."],
                ]);
            }

            if ($copy->status === 'retired') {
                throw ValidationException::withMessages([
                    'status' => ["Accession no. {$copy->accessionNumber} has already been removed from the catalog."],
                ]);
            }

            if ($this->penalties->openForCopy($copy->copyID)) {
                throw ValidationException::withMessages([
                    'status' => ["Accession no. {$copy->accessionNumber} has an unpaid penalty. Settle it first."],
                ]);
            }

            // A shelf copy that an accepted reservation is waiting on can't be taken out from under it.
            if ($copy->status === 'available') {
                $free = $this->bookCopies->availableCountForBook($copy->bookID) - $this->reservations->acceptedCountForBook($copy->bookID);

                if ($free <= 0) {
                    throw ValidationException::withMessages([
                        'status' => ["Accession no. {$copy->accessionNumber} is held for an accepted reservation. Let the student collect it first."],
                    ]);
                }
            }

            $retired = $this->bookCopies->update($copy, ['status' => 'retired']);
            $this->stockLog->removed($copy->book, $retired, $reason, $note);

            return $retired;
        });
    }

    /**
     * The book already catalogued under this ISBN (in any usual form), shaped for the Add Copies window; null when
     * the ISBN is new to the library. A scan of a book the library owns means "another copy", not "another book".
     *
     * @return array{bookUuid: string, title: string, authors: string[], coverImageURL: ?string, callNumber: ?string, copies: int}|null
     */
    public function findBookByIsbn(string $isbn): ?array
    {
        $book = $this->books->findByIsbn(BookMetadataService::isbnVariants($isbn));

        if (! $book) {
            return null;
        }

        return [
            'bookUuid'      => $book->uuid,
            'title'         => $book->title,
            'authors'       => $book->authors()->pluck('name')->values()->all(),
            'coverImageURL' => $book->coverImageURL,
            'callNumber'    => $book->callNumber,
            'copies'        => $book->copies()->where('status', '!=', 'retired')->count(),
        ];
    }

    public function createBook(array $validated): Book
    {
        // The same ISBN is the same book: another physical one is a copy of it (see addCopies), never a second record.
        if (! empty($validated['isbn']) && $existing = $this->findBookByIsbn($validated['isbn'])) {
            throw ValidationException::withMessages([
                'isbn' => ["This ISBN is already in the catalog as \"{$existing['title']}\". Add a copy of that book instead of adding it again."],
            ]);
        }

        // Best-effort live lookup, done before the DB transaction opens (a
        // network call has no business holding DB locks). Returns null on
        // any miss/failure/timeout — never blocks the book from being added.
        $lookup = ! empty($validated['isbn'])
            ? $this->classification->lookupByIsbn($validated['isbn'])
            : null;

        // What was typed in "Source of fund" is matched to the list first (Donation = Donated); a new one joins the list.
        $validated['sourceOfFund'] = $this->fundSources->resolve($validated['sourceOfFund'] ?? null);

        $book = DB::transaction(function () use ($validated, $lookup) {
            // Books are shelved by area of the library now. The category column stays (the student app and reports still
            // read it), so a book added without one takes the default.
            $subject = ! empty($validated['subjectID'])
                ? $this->subjects->findOrFail($validated['subjectID'])
                : $this->subjects->firstOrCreateByName(trim((string) ($validated['subjectName'] ?? '')) ?: 'General');

            // Resolve authors first (existing IDs or find-or-create by name)
            // so the primary author's cutter number is known before the
            // class number is built below.
            $authorEntries = [];
            foreach ($validated['authors'] as $authorInput) {
                $author = ! empty($authorInput['authorID'])
                    ? $this->authors->findOrFail($authorInput['authorID'])
                    : $this->authors->firstOrCreateByName($authorInput['name']);

                $authorEntries[] = ['model' => $author, 'role' => $authorInput['role'] ?? null];
            }

            $primaryAuthor = $authorEntries[0]['model'];

            // If the lookup found a real LC classification, prefer whichever
            // candidate's letter matches the primary author's surname.
            $verifiedCutter = $lookup
                ? $this->classification->matchCutterForSurname($lookup['cutterCandidates'], $this->surnameOf($primaryAuthor->name))
                : null;

            // Assign/backfill a cutterNumber for every resolved author; the
            // primary author gets the verified cutter (if found) instead of
            // the local table's guess. Never overwrites an existing cutter,
            // so previously catalogued books keep shelving consistently.
            foreach ($authorEntries as $index => $entry) {
                $this->cutterNumber($entry['model'], $index === 0 ? $verifiedCutter : null);
            }

            // Order of trust: a number the librarian typed, then one found by ISBN. If neither is there the book is
            // saved with no Dewey number (never a guess from its category) and flagged, so someone finds or types one.
            if (! empty($validated['deweyNumber'])) {
                [$deweyClass, $deweySource, $deweyStatus] = [$validated['deweyNumber'], 'manual', 'ok'];
            } elseif (! empty($lookup['deweyClass'])) {
                [$deweyClass, $deweySource, $deweyStatus] = [$lookup['deweyClass'], $lookup['source'] ?? 'library_of_congress', 'ok'];
            } else {
                [$deweyClass, $deweySource, $deweyStatus] = [null, null, 'needs_review'];
            }

            // The call number is the Dewey class + the primary author's Cutter. Only the Cutter is stored (with a/b/c
            // on the end if another book already holds that exact pair); the call number is worked out from the two.
            $cutter = $deweyClass !== null ? $this->uniqueCutter($deweyClass, $primaryAuthor->cutterNumber) : $primaryAuthor->cutterNumber;

            $book = $this->books->create([
                'uuid'            => Str::uuid(),
                'subjectID'       => $subject->subjectID,
                'areaOfLibrary'   => $validated['areaOfLibrary'] ?? 'circulation',
                'title'           => $validated['title'],
                'deweyNumber'     => $deweyClass,
                'cutter'          => $cutter,
                'deweySource'     => $deweySource,
                'deweyStatus'     => $deweyStatus,
                'isbn'            => $validated['isbn'] ?? null,
                'publicationYear' => $validated['publicationYear'] ?? null,
                'volume'          => $validated['volume'] ?? null,
                'edition'         => $validated['edition'] ?? null,
                'pageCount'       => $validated['pageCount'] ?? null,
                'publisher'       => $validated['publisher'] ?? null,
                'sourceOfFund'    => $validated['sourceOfFund'] ?? null,
                'cost'            => $validated['cost'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
                'coverImageURL'   => $validated['coverImageURL'] ?? null,
                'shelfLocation'   => $validated['shelfLocation'] ?? null,
                // Only a ticked box makes it a new arrival; old books are catalogued too.
                'isNewArrival'    => (bool) ($validated['isNewArrival'] ?? false),
                'newArrivalAt'    => ($validated['isNewArrival'] ?? false) ? now() : null,
            ]);

            foreach ($authorEntries as $entry) {
                $book->authors()->attach($entry['model']->authorID, [
                    'uuid' => Str::uuid(),
                    'role' => $entry['role'],
                ]);
            }

            for ($i = 0; $i < $validated['quantity']; $i++) {
                // Each copy carries where it came from and what it cost, like a copy added later does.
                $this->createCopy($book, 'New book', null, [
                    'sourceOfFund' => $validated['sourceOfFund'] ?? null,
                    'cost'         => $validated['cost'] ?? null,
                    'remarks'      => $validated['remarks'] ?? null,
                ]);
            }

            return $book;
        });

        // Tell everyone about it once the book is safely saved.
        if ($book->isNewArrival) {
            $this->notifications->notifyAllStudents("New arrival: \"{$book->title}\" is now in the library.", 'new_arrival');
        }

        return $this->books->loadCatalogRelations($book);
    }

    /**
     * One title from the library's accession record (see BookImportService), saved with its copies. Unlike Add Book,
     * details the record never had may be missing: the book is saved anyway and shows as incomplete until a librarian
     * fills them in. The call number is the record's own (Dewey + Cutter), kept as checked because the library gave
     * it; without one, the book waits for a Dewey number like any other. Nothing is looked up online here.
     *
     * @param  array{title: string, authors: string[], areaOfLibrary: string, deweyNumber: ?string, cutter: ?string,
     *     isbn: ?string, publicationYear: ?int, edition: ?string, volume: ?string, pageCount: ?int, publisher: ?string,
     *     sourceOfFund: ?string, cost: ?float, remarks: ?string}  $data
     * @param  array<int, array{accessionNumber: ?int, dateReceived: ?string, sourceOfFund: ?string, cost: ?float, remarks: ?string, status: string}>  $copies
     * @param  Book|null  $existing  the book already in the catalog that these copies belong to
     */
    public function importBook(array $data, array $copies, ?Book $existing = null, string $note = 'Imported from the accession record'): Book
    {
        return DB::transaction(function () use ($data, $copies, $existing, $note) {
            $book = $existing;

            if (! $book) {
                $authors = array_map(fn (string $name) => $this->authors->firstOrCreateByName($name), $data['authors']);
                foreach ($authors as $author) {
                    try {
                        $this->cutterNumber($author);
                    } catch (\InvalidArgumentException) {
                        // Something that isn't a name was typed as the author (the record has "aug 16. 2016" once):
                        // no Cutter can be made from it, and the book comes in without one rather than stopping the import.
                    }
                }

                $dewey = $data['deweyNumber'];
                // The record's Cutter if it has one, otherwise the main author's; a/b/c is added if another book holds
                // that exact call number (two titles may never share one).
                $cutter = $data['cutter'] ?: ($authors[0]->cutterNumber ?? null);
                if ($dewey !== null && $cutter !== null) {
                    $cutter = $this->uniqueCutter($dewey, $cutter);
                }

                $book = $this->books->create([
                    'uuid'            => Str::uuid(),
                    'subjectID'       => $this->subjects->firstOrCreateByName('General')->subjectID,
                    'areaOfLibrary'   => $data['areaOfLibrary'],
                    'title'           => $data['title'],
                    'deweyNumber'     => $dewey,
                    'cutter'          => $cutter,
                    'deweySource'     => $dewey !== null ? 'accession_record' : null,
                    'deweyStatus'     => $dewey !== null ? 'ok' : 'needs_review',
                    'isbn'            => $data['isbn'],
                    'publicationYear' => $data['publicationYear'],
                    'volume'          => $data['volume'],
                    'edition'         => $data['edition'],
                    'pageCount'       => $data['pageCount'],
                    'publisher'       => $data['publisher'],
                    'sourceOfFund'    => $this->fundSources->resolve($data['sourceOfFund']),
                    'cost'            => $data['cost'],
                    'remarks'         => $data['remarks'],
                    'isNewArrival'    => false,
                ]);

                foreach ($authors as $author) {
                    $book->authors()->attach($author->authorID, ['uuid' => Str::uuid(), 'role' => null]);
                }
            }

            foreach ($copies as $copy) {
                $this->createCopy($book, 'Imported', $note, $copy);
            }

            return $book;
        });
    }

    /**
     * Sets a book's Dewey number and rebuilds its call number from it, keeping the author's Cutter. Marks the number
     * as checked (`ok`), because it came from a catalogue or from a person, never from the keyword guess.
     */
    public function applyDewey(Book $book, string $dewey, string $source): Book
    {
        $update = ['deweyNumber' => $dewey, 'deweySource' => $source, 'deweyStatus' => 'ok'];

        $primary = $book->authors()->first();
        if ($primary) {
            // Another book may already hold that exact call number: a/b/c is added, as when a book is first catalogued.
            $update['cutter'] = $this->uniqueCutter($dewey, $this->cutterNumber($primary), $book->bookID);
        }

        $before = $book->deweyNumber;
        $wasChecked = $book->deweyStatus === 'ok';

        $book->update($update);

        if ($before !== $dewey) {
            $this->stockLog->bookEdited($book, 'Dewey number: '.($before ?: '—').' → '.$dewey);
        } elseif (! $wasChecked) {
            $this->stockLog->bookEdited($book, 'Dewey number '.$dewey.' verified');
        }

        return $book->fresh();
    }

    /**
     * Looks the book's ISBN up in the Library of Congress catalogue (then Open Library) and, if a Dewey number
     * turns up, applies it. Nothing changes when there is no ISBN or no number is found.
     *
     * @return array{found: bool, reason?: string, book?: Book}
     */
    public function lookupDewey(Book $book): array
    {
        if (empty($book->isbn)) {
            return ['found' => false, 'reason' => 'This book has no ISBN to look up.'];
        }

        $result = $this->classification->lookupByIsbn($book->isbn);

        if (! $result || empty($result['deweyClass'])) {
            return ['found' => false, 'reason' => 'No Dewey number was found for this ISBN.'];
        }

        return ['found' => true, 'book' => $this->applyDewey($book, $result['deweyClass'], $result['source'] ?? 'library_of_congress')];
    }

    /**
     * For the book form: what the catalogues know about an ISBN, before the book exists. Nothing is saved.
     *
     * @return array{found: bool, deweyNumber?: string, source?: string, cutter?: ?string, year?: ?int}
     */
    public function lookupDeweyByIsbn(string $isbn): array
    {
        $result = $this->classification->lookupByIsbn($isbn);

        if (! $result || empty($result['deweyClass'])) {
            // 'unreachable' = a catalogue did not answer, so "no number" may only mean "couldn't ask".
            return ['found' => false, 'unreachable' => in_array('unreachable', $this->classification->lastStatus(), true)];
        }

        return [
            'found'       => true,
            'deweyNumber' => $result['deweyClass'],
            'source'      => $result['source'] ?? 'library_of_congress',
            'cutter'      => $result['cutterCandidates'][0] ?? null,
            'year'        => $result['publishYear'] ?? null,
        ];
    }

    /** The "check these numbers" list: every book whose Dewey number nobody has confirmed yet. */
    public function deweyReview(?string $search, int $perPage): array
    {
        $page = $this->books->paginateDeweyReview($search, $perPage);

        return [
            'books' => $page->through(fn (Book $book) => [
                'bookID'      => $book->bookID,
                'uuid'        => $book->uuid,
                'title'       => $book->title,
                'isbn'        => $book->isbn,
                'authors'     => $book->authors->pluck('name')->values(),
                'subject'     => $book->subject?->name,
                'callNumber' => $book->callNumber,
                'deweyNumber' => $book->deweyNumber,
                'deweySource' => $book->deweySource,
            ]),
            'needsReview' => $this->books->deweyReviewCount(),
        ];
    }

    public function updateBook(Book $book, array $validated): Book
    {
        if (array_key_exists('sourceOfFund', $validated)) {
            $validated['sourceOfFund'] = $this->fundSources->resolve($validated['sourceOfFund']);
        }

        $before = $this->trackedBookFields($book);

        $book = DB::transaction(function () use ($validated, $book) {
            if (! empty($validated['subjectID'])) {
                $book->subjectID = $validated['subjectID'];
            } elseif (! empty($validated['subjectName'])) {
                $subject = $this->subjects->firstOrCreateByName($validated['subjectName']);
                $book->subjectID = $subject->subjectID;
            }

            $book->fill(collect($validated)->only([
                'title', 'areaOfLibrary', 'isbn', 'publicationYear', 'volume', 'edition',
                'pageCount', 'publisher', 'sourceOfFund', 'cost', 'remarks',
                'coverImageURL', 'shelfLocation',
            ])->toArray());

            // The new-arrival mark: turning it on starts its clock (newest first on the student Home); it is kept as it
            // was when the flag didn't change, so editing a book never moves it up or down the list. Students are not
            // notified again here: that happens once, when the book is first added.
            if (array_key_exists('isNewArrival', $validated) && (bool) $validated['isNewArrival'] !== (bool) $book->isNewArrival) {
                $book->isNewArrival = (bool) $validated['isNewArrival'];
                $book->newArrivalAt = $book->isNewArrival ? now() : null;
            }

            $book->save();

            if (array_key_exists('authors', $validated)) {
                $syncData = [];
                foreach ($validated['authors'] as $authorInput) {
                    $author = ! empty($authorInput['authorID'])
                        ? $this->authors->findOrFail($authorInput['authorID'])
                        : $this->authors->firstOrCreateByName($authorInput['name']);

                    $syncData[$author->authorID] = ['uuid' => Str::uuid(), 'role' => $authorInput['role'] ?? null];
                }
                $book->authors()->sync($syncData);
            }

            return $book;
        });

        // Every field that changed goes in the stock log as one entry ("Title: A → B; Area: Circulation → Fiction").
        $changes = $this->describeBookChanges($before, $this->trackedBookFields($book->fresh()));
        if ($changes) {
            $this->stockLog->bookEdited($book, implode('; ', $changes));
        }

        // A Dewey number typed in the edit form is a person's say-so: it is marked checked and the call number follows it.
        if (! empty($validated['deweyNumber']) && $validated['deweyNumber'] !== $book->deweyNumber) {
            $book = $this->applyDewey($book->fresh(), $validated['deweyNumber'], 'manual');
        } elseif (! empty($validated['deweyNumber'])) {
            $wasChecked = $book->deweyStatus === 'ok';
            $book->update(['deweyNumber' => $validated['deweyNumber'], 'deweySource' => 'manual', 'deweyStatus' => 'ok']);
            if (! $wasChecked) {
                $this->stockLog->bookEdited($book, 'Dewey number '.$validated['deweyNumber'].' verified');
            }
        }

        return $this->books->loadCatalogRelations($book->fresh());
    }

    /**
     * Everything the librarian's "View details" and "Edit book" screens show: every stored field,
     * authors with their roles, each non-retired copy, and how much the book has been borrowed.
     */
    public function bookDetail(Book $book): array
    {
        $book->load(['subject', 'authors', 'copies' => fn ($copies) => $copies->where('status', '!=', 'retired')]);

        return [
            ...$book->only([
                'bookID', 'uuid', 'title', 'isbn', 'areaOfLibrary', 'publicationYear', 'volume', 'edition', 'pageCount',
                'publisher', 'sourceOfFund', 'cost', 'remarks', 'coverImageURL', 'shelfLocation', 'isNewArrival',
                'deweyNumber', 'deweySource', 'deweyStatus',
            ]),
            'callNumber' => $book->callNumber,
            'spineLabel' => $book->spineLabel(),
            // `pages` further down is the page photos, so the number of pages is sent under its own name.
            'subject'    => $book->subject?->only(['subjectID', 'name']),
            'authors'    => $book->authors->map(fn (Author $author) => [
                'authorID' => $author->authorID,
                'name'     => $author->name,
                'role'     => $author->pivot->role,
            ])->values(),
            'copies' => $book->copies->map(fn ($copy) => [
                'copyID'          => $copy->copyID,
                'uuid'            => $copy->uuid,
                'accessionNumber' => $copy->accessionNumber,
                'barcodeValue'    => $copy->barcodeValue,
                'status'          => $copy->status,
                // This copy's own cost (null if not recorded): where a lost/damaged penalty starts from.
                'cost'            => $copy->cost,
            ])->values(),
            'heldCopies' => $book->reservations()->where('status', 'Accepted')->count(),
            'loans'     => $this->loans->countsForBook($book->bookID),
            'pages'     => app(BookPageService::class)->forBook($book),
            'createdAt' => $book->created_at?->toIso8601String(),
        ];
    }

    /**
     * Books are soft-deleted, so loan, fine and reservation history that points at them survives.
     * Its copies are retired too, so they stop counting towards the shelf stats and can't be checked out.
     */
    /**
     * Everything that hangs off a book, gathered for the "Remove this book" window so the librarian sees exactly
     * what is tied to it before it goes: its copies, loans, fines, reservations, wishlists, page photos and log.
     * Read-only; `blockers` lists what stops the removal (a copy that is still out on loan).
     *
     * @return array<string, mixed>
     */
    public function removalSummary(Book $book): array
    {
        $book->loadMissing(['authors', 'subject']);
        $person = fn ($student) => [
            'name'      => $student?->user?->fullName ?? 'Unknown student',
            'studentID' => $student?->memberIDNumber,
        ];

        $copies = $book->copies()->where('status', '!=', 'retired')->orderBy('accessionNumber')->get();
        $copyIDs = $book->copies()->pluck('copyID');

        $loans = \App\Models\Loan::with(['student.user', 'copy'])->whereIn('copyID', $copyIDs)->orderByDesc('checkoutDate')->get();
        $activeLoans = $loans->where('status', 'Active')->values();

        $reservations = \App\Models\Reservation::with('student.user')->where('bookID', $book->bookID)->get();
        $openReservations = $reservations->whereIn('status', ['Waiting', 'Accepted'])->values();

        $penalties = \App\Models\Penalty::whereIn('copyID', $copyIDs)->get();
        $unpaid = $penalties->where('paymentStatus', 'Unpaid');

        $blockers = [];
        if ($activeLoans->isNotEmpty()) {
            $n = $activeLoans->count();
            $blockers[] = "{$n} ".Str::plural('copy', $n).' of this book '.($n === 1 ? 'is' : 'are').' still borrowed. Check '.($n === 1 ? 'it' : 'them').' in first.';
        }

        return [
            'book' => [
                'uuid'          => $book->uuid,
                'title'         => $book->title,
                'isbn'          => $book->isbn,
                'callNumber'    => $book->callNumber,
                'areaOfLibrary' => $book->areaOfLibrary,
                'subject'       => $book->subject?->name,
                'authors'       => $book->authors->pluck('name')->values(),
                'coverImageURL' => $book->coverImageURL,
            ],
            'copies' => [
                'items' => $copies->map(fn ($c) => [
                    'accessionNumber' => $c->accessionNumber,
                    'barcodeValue'    => $c->barcodeValue,
                    'status'          => $c->status,
                    'cost'            => $c->cost,
                    'sourceOfFund'    => $c->sourceOfFund,
                ])->values(),
                'alreadyRemoved' => $book->copies()->where('status', 'retired')->count(),
            ],
            'loans' => [
                'total'    => $loans->count(),
                'returned' => $loans->where('status', '!=', 'Active')->count(),
                'active'   => $activeLoans->map(fn ($l) => [
                    ...$person($l->student),
                    'accessionNumber' => $l->copy?->accessionNumber,
                    'dueDate'         => $l->dueDate?->toIso8601String(),
                ])->values(),
            ],
            'penalties' => [
                'total'        => $penalties->count(),
                'unpaidCount'  => $unpaid->count(),
                'unpaidAmount' => (float) $unpaid->sum('amount'),
            ],
            'reservations' => [
                'total' => $reservations->count(),
                'open'  => $openReservations->map(fn ($r) => [
                    ...$person($r->student),
                    'status'     => $r->status,
                    'pickupCode' => $r->pickupCode,
                ])->values(),
            ],
            'wishlists'     => \App\Models\Wishlist::where('bookID', $book->bookID)->count(),
            'pagePhotos'    => $book->pagePhotos()->count(),
            'stockLogCount' => \App\Models\BookStockLog::where('bookID', $book->bookID)->count(),
            'blockers'      => $blockers,
        ];
    }

    public function deleteBook(Book $book): void
    {
        $activeLoans = $this->bookCopies->countByStatusForBook($book->bookID, 'borrowed');

        if ($activeLoans > 0) {
            throw ValidationException::withMessages([
                'book' => ["Cannot delete: {$activeLoans} " . Str::plural('copy', $activeLoans) . ' of this book '
                    . ($activeLoans === 1 ? 'is' : 'are') . ' still borrowed. Check them in first.'],
            ]);
        }

        // Students still waiting on the book (or with a copy set aside for pickup) can't be served any more: their
        // reservations are declined, and each is told why.
        $cancelled = \App\Models\Reservation::where('bookID', $book->bookID)->whereIn('status', ['Waiting', 'Accepted'])->get();

        DB::transaction(function () use ($book, $cancelled) {
            foreach ($book->copies()->where('status', '!=', 'retired')->get() as $copy) {
                $copy->update(['status' => 'retired']);
                $this->stockLog->removed($book, $copy, 'Book removed', 'The whole book was removed from the catalog.');
            }

            $cancelled->each(fn ($reservation) => $this->reservations->update($reservation, ['status' => 'Rejected']));

            $this->books->delete($book);
        });

        $cancelled->each(fn ($reservation) => $this->notifications->send(
            $reservation->studentID,
            "Your reservation for \"{$book->title}\" was cancelled because the library removed this book from its catalog.",
            'reservation_rejected'
        ));
    }

    /**
     * @param  array{sourceOfFund?: string, cost?: float|int|string, remarks?: string|null}  $acquisition
     */
    private function createCopy(Book $book, string $reason, ?string $note = null, array $acquisition = []): BookCopy
    {
        $copy = $this->bookCopies->create([
            'uuid'            => Str::uuid(),
            'bookID'          => $book->bookID,
            // An imported copy keeps the number stamped in it; any other takes the next one (see BookCopy::booted).
            ...(isset($acquisition['accessionNumber']) ? ['accessionNumber' => $acquisition['accessionNumber']] : []),
            'barcodeValue'    => $this->bookCopies->generateUniqueBarcode(),
            'status'          => $acquisition['status'] ?? 'available',
            'sourceOfFund'    => $this->fundSources->resolve($acquisition['sourceOfFund'] ?? null),
            'cost'            => $acquisition['cost'] ?? null,
            'remarks'         => $acquisition['remarks'] ?? null,
            'dateReceived'    => $acquisition['dateReceived'] ?? null,
        ]);

        $this->stockLog->added($book, $copy, $reason, $note);

        return $copy;
    }

    /**
     * Returns the author's cutter number. If a verified one was matched from
     * a live Open Library lookup and the author doesn't have one yet, that's
     * used; otherwise falls back to (and persists) a generated one. Never
     * overwrites a cutterNumber the author already has, so existing
     * catalogued books keep shelving consistently.
     */
    private function cutterNumber(Author $author, ?string $verified = null): string
    {
        if (empty($author->cutterNumber)) {
            $this->authors->update($author, [
                'cutterNumber' => $verified ?: $this->generateCutter($author->name),
            ]);
        }

        return $author->cutterNumber;
    }

    /**
     * The Cutter part of a call number like "005.13 C67". Falls back through a/b/c... suffixes if that exact
     * Dewey number + Cutter pair is already used by another book (a deleted book keeps its number reserved).
     */
    private function uniqueCutter(string $dewey, string $cutterNumber, ?int $exceptBookID = null): string
    {
        return $this->uniqueSuffixed(
            $cutterNumber,
            fn (string $candidate) => $this->books->callNumberExists($dewey, $candidate, $exceptBookID)
        );
    }

    /**
     * Appends a/b/c... suffixes to $base until $existsCheck reports no
     * collision. Shared by the classification-code and class-number
     * generators, which both need the same "first free slug" behavior.
     */
    private function uniqueSuffixed(string $base, callable $existsCheck): string
    {
        $candidate = $base;
        $suffix = 0;

        while ($existsCheck($candidate)) {
            $candidate = $base . chr(97 + $suffix);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Generate a Cutter-style author number, e.g. "Cormen" -> "C887". The
     * letter comes from the first letter of the surname; the digits come
     * from a standard Cutter-Sanborn-style range table. Collisions are
     * resolved by consuming more letters of the surname, then by numeric
     * suffix if the surname runs out.
     */
    private function generateCutter(string $name): string
    {
        $surname = $this->surnameOf($name);
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $surname));

        if ($letters === '') {
            throw new \InvalidArgumentException('Cannot generate a cutter number from an empty surname.');
        }

        $firstLetter = $letters[0];
        $isQu = str_starts_with($letters, 'QU');

        $nextIndex = $isQu ? 3 : 2;
        $cutter = $firstLetter . $this->cutterBaseDigit($letters, $firstLetter, $isQu);

        while ($this->authors->cutterNumberExists($cutter)) {
            $nextLetter = $letters[$nextIndex] ?? null;

            if ($nextLetter === null) {
                $suffix = 1;
                $candidate = $cutter . $suffix;
                while ($this->authors->cutterNumberExists($candidate)) {
                    $suffix++;
                    $candidate = $cutter . $suffix;
                }

                return $candidate;
            }

            $cutter .= $this->cutterExpansionDigit($nextLetter);
            $nextIndex++;
        }

        return $cutter;
    }

    private function surnameOf(string $name): string
    {
        if (str_contains($name, ',')) {
            return trim(explode(',', $name)[0]);
        }

        $parts = preg_split('/\s+/', trim($name));

        return end($parts);
    }

    private function cutterBaseDigit(string $letters, string $firstLetter, bool $isQu): int
    {
        if ($isQu) {
            return $this->cutterLookup($letters[2] ?? '', self::CUTTER_TABLE_QU);
        }

        $second = $letters[1] ?? '';

        if (in_array($firstLetter, ['A', 'E', 'I', 'O', 'U'])) {
            return $this->cutterLookup($second, self::CUTTER_TABLE_FIRST_VOWEL);
        }

        if ($firstLetter === 'S') {
            return $this->cutterLookup($second, self::CUTTER_TABLE_FIRST_S);
        }

        return $this->cutterLookup($second, self::CUTTER_TABLE_DEFAULT);
    }

    private function cutterExpansionDigit(string $letter): int
    {
        return $this->cutterLookup($letter, self::CUTTER_EXPANSION_TABLE);
    }

    private function cutterLookup(string $letter, array $ranges): int
    {
        if ($letter === '') {
            return min($ranges);
        }

        foreach ($ranges as $range => $digit) {
            [$start, $end] = explode('-', $range);
            if ($letter >= $start && $letter <= $end) {
                return $digit;
            }
        }

        return end($ranges);
    }
}
