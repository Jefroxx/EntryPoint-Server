<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Loan;
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
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Read-only data behind the student app: profile and counters, the student's
 * own loans / fines / visits / redemptions, and a catalog carrying live
 * availability. Everything here is scoped to the signed-in student.
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
    ) {
    }

    public function profile(Student $student): array
    {
        $user = $student->user;
        $activeReservations = $this->reservations->activeCountForStudent($student->studentID);

        return [
            'profile' => [
                'firstName'          => $user->firstName,
                'middleInitial'      => $user->middleInitial,
                'lastName'           => $user->lastName,
                'fullName'           => $user->fullName,
                'email'              => $user->email,
                'phoneNumber'        => $user->phoneNumber,
                'address'            => $user->address,
                'studentIDNumber'    => $student->studentIDNumber,
                'academicProgram'    => $student->academicProgram,
                'barcodeValue'       => $student->barcodeValue,
                'registrationStatus' => $student->registrationStatus,
            ],
            'points'      => (int) $student->knowledgeScore,
            'visitStreak' => (int) $student->visitStreak,
            'stats'       => [
                'activeLoans'        => $this->loans->activeCountForStudent($student->studentID),
                'overdueLoans'       => $this->loans->overdueCountForStudent($student->studentID),
                'readyReservations'  => $this->reservations->readyCountForStudent($student->studentID),
                'activeReservations' => $activeReservations,
                'slotsLeft'          => max(ReservationService::MAX_ACTIVE_RESERVATIONS - $activeReservations, 0),
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

            return [
                'loanID'       => $loan->loanID,
                'status'       => $reported ? 'Reported' : $loan->status,
                'checkoutDate' => $loan->checkoutDate,
                'dueDate'      => $loan->dueDate,
                'returnDate'   => $loan->returnDate,
                // whole days from today to the due date; negative = overdue
                'daysLeft'     => (int) now()->startOfDay()->diffInDays(Carbon::parse($loan->dueDate)->startOfDay(), false),
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
            $loan = $penalty->loan;
            $book = $loan->copy->book;
            $rule = $this->penalties->ruleForArea($book->areasOfLibrary);
            $unit = $rule?->rateUnit === 'hour' ? 'hour' : 'day';

            return [
                'penaltyID'     => $penalty->penaltyID,
                'amount'        => (float) $penalty->amount,
                'paymentStatus' => $penalty->paymentStatus,
                'computedAt'    => $penalty->computedAt,
                'settledAt'     => $penalty->settledAt,
                'loanReturned'  => $loan->status === 'Returned',
                'rate'          => $rule ? (float) $rule->rate : null,
                'rateUnit'      => $unit,
                'unitsLate'     => $this->circulation->elapsedUnits($loan->dueDate, $loan->returnDate ?? now(), $unit),
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
        );

        $queues = $this->reservations->waitingCountsByBook($paginator->getCollection()->pluck('bookID')->all());

        return $paginator->through(fn (Book $book) => $this->catalogRow($book, $queues[$book->bookID] ?? 0));
    }

    public function catalogBook(Book $book): array
    {
        $this->books->loadForStudent($book);
        $queues = $this->reservations->waitingCountsByBook([$book->bookID]);

        return $this->catalogRow($book, $queues[$book->bookID] ?? 0);
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
    // catalog columns are now `classNumber` / `areasOfLibrary`.
    private function catalogRow(Book $book, int $queueLength): array
    {
        return $this->bookBrief($book) + [
            'callNumber'      => $book->classNumber,
            'isbn'            => $book->isbn,
            'publicationYear' => $book->publicationYear,
            'shelfLocation'   => $book->shelfLocation,
            'circulationType' => $book->areasOfLibrary,
            'totalCopies'     => (int) ($book->total_copies ?? 0),
            'availableCopies' => (int) ($book->available_copies ?? 0),
            'queueLength'     => $queueLength,
        ];
    }

    private function bookBrief(Book $book): array
    {
        return [
            'bookID'        => $book->bookID,
            'title'         => $book->title,
            'coverImageURL' => $book->coverImageURL,
            'subject'       => $book->subject
                ? ['subjectID' => $book->subject->subjectID, 'name' => $book->subject->name]
                : null,
            'authors'       => $book->authors->map(fn (Author $author) => $author->name)->values(),
        ];
    }
}
