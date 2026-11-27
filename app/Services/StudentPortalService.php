<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\Penalty;
use App\Models\PointRedemption;
use App\Models\Student;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\PointRedemptionRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Data behind the student app: profile and counters, the student's own
 * loans / fines / visits / redemptions, and a catalog carrying live
 * availability. Everything here is scoped to the signed-in student. The one
 * write is updateContact(): the contact details a student may change themselves.
 */
class StudentPortalService
{
    public function __construct(
        private LoanRepositoryInterface $loans,
        private PenaltyRepositoryInterface $penalties,
        private ReservationRepositoryInterface $reservations,
        private WishlistRepositoryInterface $wishlists,
        private AttendanceLogRepositoryInterface $attendanceLogs,
        private PointRedemptionRepositoryInterface $redemptions,
        private BookRepositoryInterface $books,
        private BookSubjectRepositoryInterface $subjects,
        private CirculationService $circulation,
        private UserRepositoryInterface $users,
    ) {
    }

    /** Phone, address and birth date only (see UpdateStudentContactRequest). Blank clears the field. */
    public function updateContact(Student $student, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(['phoneNumber', 'address', 'birthDate']));
        $fields = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $fields);

        $this->users->update($student->user, $fields);

        if (isset($data['memberIDNumber'])) {
            $student->update(['memberIDNumber' => trim($data['memberIDNumber'])]);
        }
    }

    public function profile(Student $student): array
    {
        $user = $student->user;
        $activeReservations = $this->reservations->activeCountForStudent($student->studentID);
        $activeLoans = $this->loans->activeCountForStudent($student->studentID);

        return [
            'profile' => [
                'firstName'          => $user->firstName,
                'middleInitial'      => $user->middleInitial,
                'lastName'           => $user->lastName,
                'fullName'           => $user->fullName,
                'email'              => $user->email,
                'phoneNumber'        => $user->phoneNumber,
                'address'            => $user->address,
                'birthDate'          => $user->birthDate ? Carbon::parse($user->birthDate)->toDateString() : null,
                'memberIDNumber'    => $student->memberIDNumber,
                'academicProgram'    => $student->academicProgram,
                'memberType'         => $student->memberType,
                'department'         => $student->department,
                'barcodeValue'       => $student->barcodeValue,
                'registrationStatus' => $student->registrationStatus,
            ],
            'points'      => (int) $student->knowledgeScore,
            'visitStreak' => $student->currentVisitStreak(),
            'stats'       => [
                'activeLoans'        => $activeLoans,
                'overdueLoans'       => $this->loans->overdueCountForStudent($student->studentID),
                'readyReservations'  => $this->reservations->readyCountForStudent($student->studentID),
                'activeReservations' => $activeReservations,
                // The limit covers books the student holds now (loans) plus waiting or accepted reservations.
                'maxBooks'           => AppSettings::maxBooks(),
                'slotsLeft'          => max(AppSettings::maxBooks() - $activeLoans - $activeReservations, 0),
                'unpaidFines'        => $this->penalties->unpaidTotalForStudent($student->studentID),
                'wishlistCount'      => $this->wishlists->wishlistCountForStudent($student->studentID),
                'cartCount'          => $this->wishlists->cartCountForStudent($student->studentID),
            ],
        ];
    }

    public function loans(Student $student): BaseCollection
    {
        return $this->loans->forStudentWithBooks($student->studentID)->map(function (Loan $loan) {
            $reported = $loan->status === 'Active'
                && $loan->selfReturnReport
                && $loan->selfReturnReport->verificationStatus === 'Pending';

            // A rejected report: the student sees why, and when they can report again.
            $rejected = $loan->status === 'Active'
                && $loan->selfReturnReport
                && $loan->selfReturnReport->verificationStatus === 'Rejected';

            return [
                'loanID'       => $loan->loanID,
                'uuid'         => $loan->uuid,
                'status'       => $reported ? 'Reported' : $loan->status,
                'checkoutDate' => $loan->checkoutDate,
                'dueDate'      => $loan->dueDate,
                'returnDate'   => $loan->returnDate,
                // whole days from today to the due date; negative = overdue
                'daysLeft'     => (int) now()->startOfDay()->diffInDays(Carbon::parse($loan->dueDate)->startOfDay(), false),
                'rejectionReason'  => $rejected ? $loan->selfReturnReport->rejectionReason : null,
                'canReportAgainAt' => $rejected ? $this->circulation->selfReturnCooldownEnds($loan->selfReturnReport)->toIso8601String() : null,
                'book'         => $this->bookBrief($loan->copy->book),
            ];
        });
    }

    /**
     * @return array{penalties: BaseCollection, unpaidTotal: float}
     */
    public function penalties(Student $student): array
    {
        $rows = $this->penalties->forStudentWithBooks($student->studentID)->map(function (Penalty $penalty) {
            // A late fee always has its loan; a lost or damaged book may not (lost inside the library).
            $loan = $penalty->loan;
            $book = $penalty->copy->book;
            // 'overdue' for a late return; 'lost' or 'damaged' when the student owes for the book itself, which has
            // no rate or days late (they pay the amount or replace the book).
            $kind = $penalty->isForCopy() ? $penalty->penaltyType->kind : 'overdue';
            $rule = $kind === 'overdue' ? $this->penalties->ruleForArea($book->areaOfLibrary) : null;
            $unit = $rule?->rateUnit === 'hour' ? 'hour' : 'day';

            return [
                'penaltyID'     => $penalty->penaltyID,
                'uuid'          => $penalty->uuid,
                'kind'          => $kind,
                'reason'        => $penalty->isForCopy() ? $penalty->reason : null,
                'studentChoice' => $penalty->isForCopy() ? $penalty->studentChoice : null,
                'amount'        => (float) $penalty->amount,
                'paymentStatus' => $penalty->paymentStatus,
                'computedAt'    => $penalty->computedAt,
                'settledAt'     => $penalty->settledAt,
                'loanReturned'  => ! $loan || $loan->status === 'Returned',
                'rate'          => $rule ? (float) $rule->rate : null,
                'rateUnit'      => $unit,
                'unitsLate'     => $kind === 'overdue' && $loan ? $this->circulation->elapsedUnits($loan->dueDate, $loan->returnDate ?? now(), $unit) : 0,
                'book'          => $this->bookBrief($book),
            ];
        });

        return [
            'penalties'   => $rows,
            'unpaidTotal' => $rows->where('paymentStatus', 'Unpaid')->sum('amount'),
        ];
    }

    /**
     * @return array{visits: BaseCollection, recentDates: BaseCollection}
     */
    public function attendance(Student $student): array
    {
        $logs = $this->attendanceLogs->recentForStudent($student->studentID, 30);

        return [
            'visits'      => $logs->take(10)->values(),
            // dates (Y-m-d) with at least one visit in the last 7 days
            'recentDates' => $logs
                ->filter(fn ($log) => $log->entryTime->greaterThanOrEqualTo(now()->subDays(6)->startOfDay()))
                ->map(fn ($log) => $log->entryTime->toDateString())
                ->unique()
                ->values(),
        ];
    }

    public function redemptions(Student $student): BaseCollection
    {
        return $this->redemptions->forStudentWithItem($student->studentID)->map(fn (PointRedemption $redemption) => [
            'redemptionID' => $redemption->redemptionID,
            'itemName'     => $redemption->item?->name ?? 'Removed item',
            'quantity'     => $redemption->quantity,
            'pointsSpent'  => $redemption->pointsSpent,
            'status'       => $redemption->fulfillmentStatus,
            'redeemedAt'   => $redemption->redeemedAt,
        ]);
    }

    /**
     * Catalog with live availability: search, subject, "available now", paginated.
     */
    public function catalog(array $filters): LengthAwarePaginator
    {
        $paginator = $this->books->paginateForStudent(
            $filters['search'] ?? null,
            isset($filters['subjectID']) ? (int) $filters['subjectID'] : null,
            (bool) ($filters['availableOnly'] ?? false),
            (int) ($filters['perPage'] ?? 24),
            (bool) ($filters['newArrivalsOnly'] ?? false),
            $filters['area'] ?? null,
        );

        $queues = $this->reservations->waitingCountsByBook($paginator->getCollection()->pluck('bookID')->all());

        return $paginator->through(fn (Book $book) => $this->catalogRow($book, $queues[$book->bookID] ?? 0));
    }

    /** The ten main classes of the Dewey Decimal system, by their first digit. */
    private const DEWEY_CLASSES = [
        '0' => 'Computers and General Works', '1' => 'Philosophy and Psychology', '2' => 'Religion',
        '3' => 'Social Sciences', '4' => 'Language', '5' => 'Science', '6' => 'Technology',
        '7' => 'Arts and Recreation', '8' => 'Literature', '9' => 'History and Geography',
    ];

    /** How many of the ten places "Recommended" keeps for popular books when the student has a history. */
    private const POPULAR_SHARE = 3;

    /** The Dewey main class of a book ("5" for 512.5), or null when it has no Dewey number. */
    private function deweyClassOf(?string $deweyNumber): ?string
    {
        return $deweyNumber !== null && preg_match('/^\s*(\d)/', $deweyNumber, $m) ? $m[1] : null;
    }

    /**
     * "Recommended for you", a mix of the two things that predict a good pick:
     *  - what the student reads: books on the shelf in the Dewey classes they borrow from most (500s Science, say),
     *    across ALL their loans (returned ones too), their own subjects first inside a class, then the books everyone
     *    borrows, then A to Z. A class is wider than a subject, so one Biology loan still brings up Chemistry.
     *  - what is popular: the most-borrowed books of any class, which keep the list fresh and are all a new student gets.
     * With a history, up to POPULAR_SHARE places go to popular books and the rest to the student's classes; whichever
     * side runs short leaves its places to the other. Books they have already borrowed are left out.
     *
     * @return array{books: BaseCollection, subjects: array<int, string>, classes: array<int, string>, loanCount: int}
     */
    public function recommendations(Student $student, int $limit = 10): array
    {
        // A book's class comes from its own Dewey number, or, while that is not looked up yet, from its subject's.
        $history = Loan::where('loans.studentID', $student->studentID)
            ->join('book_copies', 'book_copies.copyID', '=', 'loans.copyID')
            ->join('books', 'books.bookID', '=', 'book_copies.bookID')
            ->leftJoin('book_subjects', 'book_subjects.subjectID', '=', 'books.subjectID')
            ->get(['books.bookID', 'books.subjectID', 'books.deweyNumber', 'book_subjects.classificationCode']);

        $borrowed = $history->pluck('bookID')->unique()->all();
        // subjectID => how many loans the student has had in it, biggest first
        $weights = $history->whereNotNull('subjectID')->countBy('subjectID')->sortDesc();
        // Dewey main class => how many loans the student has had in it, biggest first
        $classWeights = $history->map(fn ($row) => $this->deweyClassOf($row->deweyNumber ?? $row->classificationCode))
            ->filter(fn ($c) => $c !== null)->countBy()->sortDesc();

        // bookID => how many times anyone has borrowed it
        $popularity = Loan::join('book_copies', 'book_copies.copyID', '=', 'loans.copyID')
            ->selectRaw('book_copies.bookID as bookID, count(*) as total')
            ->groupBy('book_copies.bookID')->pluck('total', 'bookID');

        $onShelf = fn () => Book::with(['subject', 'authors'])
            ->withCount([
                'copies as total_copies'      => fn ($q) => $q->where('status', '!=', 'retired'),
                'copies as available_copies'  => fn ($q) => $q->where('status', 'available'),
                'reservations as held_copies' => fn ($q) => $q->where('status', 'Accepted'),
            ])
            ->whereNotIn('bookID', $borrowed)
            // The same "something is really free" rule as the catalog's Available filter.
            ->whereRaw("(select count(*) from book_copies where book_copies.bookID = books.bookID and book_copies.status = 'available')
                > (select count(*) from reservations where reservations.bookID = books.bookID and reservations.status = 'Accepted')");

        $byPopularity = fn (Book $a, Book $b) => (($popularity[$b->bookID] ?? 0) <=> ($popularity[$a->bookID] ?? 0))
            ?: strcmp($a->title, $b->title);

        $shelf = $onShelf()->get();

        // The student's side: books in their classes (or, for a book with no Dewey number, their subjects).
        $personal = collect();
        if ($classWeights->isNotEmpty() || $weights->isNotEmpty()) {
            $classWeight = fn (Book $book) => $classWeights[$this->deweyClassOf($book->deweyNumber ?? $book->subject?->classificationCode)] ?? 0;
            $subjectWeight = fn (Book $book) => $weights[$book->subjectID] ?? 0;
            $personal = $shelf->filter(fn (Book $book) => $classWeight($book) > 0 || $subjectWeight($book) > 0)
                ->sort(fn (Book $a, Book $b) => ($classWeight($b) <=> $classWeight($a))
                    ?: ($subjectWeight($b) <=> $subjectWeight($a))
                    ?: $byPopularity($a, $b))
                ->values();
        }

        // Everyone's side: the most borrowed of the rest.
        $popular = $shelf->whereNotIn('bookID', $personal->pluck('bookID')->all())->sort($byPopularity)->values();

        // Share the places: popular books keep POPULAR_SHARE of them (all of them for a student with no history), and
        // whichever side has too few books hands its unused places to the other.
        $popularPlaces = $personal->isEmpty() ? $limit : min(self::POPULAR_SHARE, $popular->count());
        $mine = $personal->take($limit - $popularPlaces);
        $theirs = $popular->take($limit - $mine->count());
        $picked = $mine->concat($theirs)->values();

        $names = $mine->isNotEmpty()
            ? BookSubject::whereIn('subjectID', $weights->keys()->take(2)->all())->pluck('name', 'subjectID')
            : collect();
        $queues = $this->reservations->waitingCountsByBook($picked->pluck('bookID')->all());

        return [
            'books'     => $picked->map(fn (Book $b) => $this->catalogRow($b, $queues[$b->bookID] ?? 0))->values(),
            // The student's top subjects and Dewey classes (up to two each), in order, only when they drove the picks.
            'subjects'  => $weights->keys()->take(2)->map(fn ($id) => $names[$id] ?? null)->filter()->values()->all(),
            'classes'   => $mine->isNotEmpty()
                ? $classWeights->keys()->take(2)->map(fn ($c) => self::DEWEY_CLASSES[$c] ?? null)->filter()->values()->all()
                : [],
            'loanCount' => $history->count(),
        ];
    }

    public function catalogBook(Book $book): array
    {
        $this->books->loadForStudent($book);
        $queues = $this->reservations->waitingCountsByBook([$book->bookID]);

        return [
            ...$this->catalogRow($book, $queues[$book->bookID] ?? 0),
            'pages' => app(BookPageService::class)->forBook($book),
        ];
    }

    /**
     * The areas of the library that hold at least one book, in the library's own order, with how many books each
     * holds: what students browse by (they replaced genres on the student side).
     */
    public function areas(): BaseCollection
    {
        $counts = Book::selectRaw('areaOfLibrary, COUNT(*) AS books')->groupBy('areaOfLibrary')->pluck('books', 'areaOfLibrary');

        return LoanPeriod::orderBy('sortOrder')->orderBy('name')->get()
            ->filter(fn (LoanPeriod $area) => ($counts[$area->area] ?? 0) > 0)
            ->map(fn (LoanPeriod $area) => ['key' => $area->area, 'name' => $area->name, 'books' => (int) $counts[$area->area]])
            ->values();
    }

    public function subjects(): BaseCollection
    {
        return $this->subjects->withBooksCount()
            ->filter(fn ($subject) => $subject->books_count > 0)
            ->map(fn ($subject) => [
                'subjectID' => $subject->subjectID,
                'name'      => $subject->name,
                'books'     => $subject->books_count,
            ])
            ->values();
    }

    // The student app still speaks `callNumber` / `circulationType`; the
    // catalog columns are now `deweyNumber` + `cutter` / `areaOfLibrary`.
    private function catalogRow(Book $book, int $queueLength): array
    {
        return $this->bookBrief($book) + [
            // Null while the Dewey number is unchecked; the app shows "Call number pending".
            'callNumber'      => $book->publicCallNumber(),
            'isbn'            => $book->isbn,
            'publicationYear' => $book->publicationYear,
            'shelfLocation'   => $book->shelfLocation,
            'circulationType' => $book->areaOfLibrary,
            // Where it is in the library: what students browse by (Discover and Home filter on it).
            'area'            => ['key' => $book->areaOfLibrary, 'name' => $book->libraryArea?->name ?? \Illuminate\Support\Str::headline((string) $book->areaOfLibrary)],
            'totalCopies'     => (int) ($book->total_copies ?? 0),
            // Copies held for an accepted reservation aren't up for grabs.
            'availableCopies' => max(0, (int) ($book->available_copies ?? 0) - (int) ($book->held_copies ?? 0)),
            'queueLength'     => $queueLength,
        ];
    }

    private function bookBrief(Book $book): array
    {
        return [
            'bookID'        => $book->bookID,
            'uuid'          => $book->uuid,
            'title'         => $book->title,
            'coverImageURL' => $book->coverImageURL,
            'isNewArrival'  => (bool) $book->isNewArrival,
            'subject'       => $book->subject
                ? ['subjectID' => $book->subject->subjectID, 'name' => $book->subject->name]
                : null,
            'authors'       => $book->authors->map(fn (Author $author) => $author->name)->values(),
        ];
    }
}
