<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\SelfReturnReport;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\LoanPeriodRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\SelfReturnReportRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CirculationService
{
    public function __construct(
        private LoanRepositoryInterface $loans,
        private BookCopyRepositoryInterface $bookCopies,
        private ReservationRepositoryInterface $reservations,
        private PenaltyRepositoryInterface $penalties,
        private SelfReturnReportRepositoryInterface $selfReturnReports,
        private LoanPeriodRepositoryInterface $loanPeriods,
        private ReservationService $reservationService,
        private NotificationService $notifications,
        private AchievementService $achievements,
    ) {
    }

    public function index(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->loans->paginate($search, $status, $perPage);
    }

    /**
     * @return array{active: int, dueSoon: int, overdue: int}
     */
    public function stats(): array
    {
        return $this->loans->stats();
    }

    public function penaltiesIndex(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        // penaltyType -> penalty_type: matches the frontend's naming, kept
        // out of the model layer since it's purely a response-shape concern.
        return $this->penalties->paginate($search, $status, $perPage)->through(function (Penalty $penalty) {
            $array = $penalty->toArray();
            $array['penalty_type'] = $penalty->penaltyType;
            unset($array['penaltyType']);

            return $array;
        });
    }

    /**
     * @return array{unpaidCount: int, unpaidTotal: float, paidCount: int}
     */
    public function penaltyStats(): array
    {
        return $this->penalties->stats();
    }

    public function settlePenalty(Penalty $penalty): Penalty
    {
        if ($penalty->paymentStatus === 'Paid') {
            throw ValidationException::withMessages([
                'penalty' => ['This penalty is already paid.'],
            ]);
        }

        $this->penalties->update($penalty, [
            'paymentStatus' => 'Paid',
            'settledAt'     => now(),
        ]);

        return $penalty->fresh(['loan.student.user', 'loan.copy.book', 'penaltyType']);
    }

    public function checkout(array $validated, int $actingLibrarianID): Loan
    {
        $loan = DB::transaction(function () use ($validated) {
            $copy = $this->bookCopies->lockForUpdateWithBook($validated['copyID']);

            if ($copy->status !== 'available') {
                throw ValidationException::withMessages([
                    'copyID' => ["This copy is currently '{$copy->status}' and cannot be checked out."],
                ]);
            }

            // A student collecting a book they have an accepted reservation for is collecting that reservation,
            // even if the librarian checked the book out without picking it; otherwise it would stay Accepted
            // and keep holding a copy the student already has.
            if (empty($validated['reservationID'])) {
                $own = $this->reservations->acceptedForStudentAndBook($validated['studentID'], $copy->bookID);

                if ($own) {
                    $validated['reservationID'] = $own->reservationID;
                }
            }

            $reservation = null;
            if (empty($validated['reservationID'])
                && $this->bookCopies->availableCountForBook($copy->bookID) <= $this->reservations->acceptedCountForBook($copy->bookID)) {
                throw ValidationException::withMessages([
                    'copyID' => ['Every available copy of this book is held for an accepted reservation.'],
                ]);
            }

            if (! empty($validated['reservationID'])) {
                $reservation = $this->reservations->findOrFail($validated['reservationID']);

                if ($reservation->studentID != $validated['studentID']) {
                    throw ValidationException::withMessages([
                        'reservationID' => ['This reservation does not belong to the specified student.'],
                    ]);
                }

                if ($reservation->bookID !== $copy->bookID) {
                    throw ValidationException::withMessages([
                        'reservationID' => ['This reservation is for a different book title.'],
                    ]);
                }

                if ($reservation->status !== 'Accepted') {
                    throw ValidationException::withMessages([
                        'reservationID' => ["Reservation must be 'Accepted' before converting to a loan."],
                    ]);
                }
            }

            $this->assertLoanable($copy->book);

            $loan = $this->loans->create([
                'uuid'         => Str::uuid(),
                'studentID'    => $validated['studentID'],
                'copyID'       => $copy->copyID,
                'loanType'     => $copy->book->areaOfLibrary,
                'checkoutDate' => now(),
                'dueDate'      => $this->computeDueDate($copy->book),
                'status'       => 'Active',
            ]);

            $this->bookCopies->update($copy, ['status' => 'borrowed']);

            if ($reservation) {
                $this->reservations->update($reservation, ['status' => 'Fulfilled']);
            }

            return $loan;
        });

        $this->loans->loadForResponse($loan);

        // Borrowing counts toward achievements (books borrowed, genres, reserved books picked up).
        $this->achievements->evaluateForStudent($loan->student);

        $this->notifications->send(
            $loan->studentID,
            "You've checked out \"{$loan->copy->book->title}\". Due back by {$loan->dueDate->format('M d, Y')}.",
            'loan_checkout'
        );

        $this->notifications->notifyAllLibrarians(
            "{$loan->student->user->fullName} checked out \"{$loan->copy->book->title}\".",
            'loan_checked_out',
            $actingLibrarianID
        );

        return $loan;
    }

    /**
     * Librarian-assisted return — the student hands the physical book back
     * at the counter and staff processes it directly (as opposed to
     * studentSubmitSelfReturn(), which stages a report for later librarian
     * verification).
     */
    /**
     * Everything a checkout receipt shows: who borrowed what (the book's full record and the copy's
     * accession number), when it's due, and what a late return costs under the current fine rule.
     * Fines are counted from the due date (see accruePenalty()), so that is what the receipt states. The fine
     * rule is the one in force now; the dates are the loan's own.
     */
    public function receipt(Loan $loan, ?string $printedBy = null): array
    {
        $loan->loadMissing(['student.user', 'copy.book.authors', 'copy.book.subject']);
        $book = $loan->copy->book;
        $rule = $this->penalties->ruleForArea($book->areaOfLibrary);

        return [
            'receiptNumber' => 'L-' . str_pad((string) $loan->loanID, 6, '0', STR_PAD_LEFT),
            'status'        => $loan->status,
            'checkoutDate'  => $loan->checkoutDate?->toIso8601String(),
            'dueDate'       => $loan->dueDate?->toIso8601String(),
            'returnDate'    => $loan->returnDate?->toIso8601String(),
            'student'       => [
                'name'            => $loan->student->user->fullName,
                'studentIDNumber' => $loan->student->studentIDNumber,
                'program'         => $loan->student->academicProgram,
            ],
            'book' => [
                ...$book->only([
                    'title', 'isbn', 'areaOfLibrary', 'publicationYear', 'volume', 'edition', 'pages', 'publisher',
                ]),
                'callNumber'      => $book->classNumber,
                'subject'         => $book->subject?->name,
                'authors'         => $book->authors->pluck('name')->values(),
                'accessionNumber' => $loan->copy->accessionNumber,
                'barcodeValue'    => $loan->copy->barcodeValue,
            ],
            'fine' => $rule ? [
                'rate'     => (float) $rule->rate,
                'rateUnit' => $rule->rateUnit,
            ] : null,
            'printedBy' => $printedBy,
        ];
    }

    /**
     * Everything the "Return this book?" window shows before anything changes: the loan, the book, the student,
     * and the fine that returning the book right now would charge (worked out exactly as it is when it is returned).
     *
     * @return array<string, mixed>
     */
    public function returnPreview(Loan $loan, ?SelfReturnReport $report = null): array
    {
        if ($loan->status !== 'Active') {
            throw ValidationException::withMessages([
                'loan' => ["Only an 'Active' loan can be returned."],
            ]);
        }

        $loan->load(['student.user', 'copy.book.authors']);
        $copy = $loan->copy;
        $book = $copy->book;
        $student = $loan->student;
        $user = $student->user;

        $rule = $this->penalties->ruleForArea($book->areaOfLibrary);
        $unitsLate = $rule ? $this->lateUnits($loan->dueDate, now(), $rule) : 0;

        // An overdue book can already have its fine on record (the hourly job writes it), and the desk may have
        // marked it paid in the Penalties tab: then the window can say so itself instead of asking.
        $onRecord = $loan->penalties()->latest('computedAt')->first();

        return [
            // Set when the window is for a student's self-return report, which adds when they reported it.
            'report' => $report ? [
                'reportID'   => $report->reportID,
                'reportedAt' => $report->reportedAt->toIso8601String(),
            ] : null,
            'loan' => [
                'loanID'     => $loan->loanID,
                'status'     => $loan->status,
                'checkoutAt' => $loan->checkoutDate->toIso8601String(),
                'dueAt'      => $loan->dueDate->toIso8601String(),
                'overdue'    => $loan->dueDate->isPast(),
            ],
            'book' => [
                'title'           => $book->title,
                'authors'         => $book->authors->pluck('name')->values(),
                'isbn'            => $book->isbn,
                'callNumber'      => $book->classNumber,
                'areaOfLibrary'   => $book->areaOfLibrary,
                'accessionNumber' => $copy->accessionNumber,
                'barcodeValue'    => $copy->barcodeValue,
            ],
            'student' => [
                'name'            => $user->fullName,
                'studentIDNumber' => $student->studentIDNumber,
                'academicProgram' => $student->academicProgram,
                'email'           => $user->email,
                'phoneNumber'     => $user->phoneNumber,
                // Fines from other loans that are still owed, so the desk sees the whole picture.
                // (not counting this loan's own fine, which the fee box shows)
                'unpaidFinesTotal' => max(0.0, $this->penalties->unpaidTotalForStudent($student->studentID)
                    - ($onRecord && $onRecord->paymentStatus !== 'Paid' ? (float) $onRecord->amount : 0.0)),
            ],
            'fine' => [
                // False when the collection has no fine rule: a late book is then not charged at all.
                'hasRule'   => (bool) $rule,
                'unitsLate' => $unitsLate,
                // Days after the due time during which nothing is charged; the units above are counted after it.
                'graceDays' => (int) ($rule?->gracePeriodDays ?? 0),
                'rateUnit'  => $rule?->rateUnit,
                'rate'      => $rule ? (float) $rule->rate : null,
                'amount'    => $rule ? round($rule->rate * $unitsLate, 2) : 0.0,
                // null when no fine has been written for this loan yet.
                'onRecord'  => $onRecord ? [
                    'amount'        => (float) $onRecord->amount,
                    'paymentStatus' => $onRecord->paymentStatus,
                    'settledAt'     => $onRecord->settledAt?->toIso8601String(),
                ] : null,
            ],
        ];
    }

    /**
     * Returns an active loan. `$finePaid` is the librarian saying the student has paid the late fee at the desk:
     * the fine is then settled in the same step; otherwise it stays on record as unpaid.
     */
    public function returnBook(Loan $loan, bool $finePaid = false): Loan
    {
        // A book already handed over and waiting to be checked finishes as "in good condition".
        if ($loan->status === 'Received') {
            return $this->finishReturn($loan, 'good');
        }

        if ($loan->status !== 'Active') {
            throw ValidationException::withMessages([
                'loan' => ["Only an 'Active' loan can be returned."],
            ]);
        }

        DB::transaction(function () use ($loan, $finePaid) {
            $penalty = $this->markReturned($loan);

            if ($finePaid && $penalty && $penalty->paymentStatus !== 'Paid') {
                $this->penalties->update($penalty, ['paymentStatus' => 'Paid', 'settledAt' => now()]);
            }
        });

        $this->loans->loadForResponse($loan);

        // Returning counts toward achievements (on-time returns, and the run of them).
        $this->achievements->evaluateForStudent($loan->student);

        $this->notifications->send(
            $loan->studentID,
            "Your return of \"{$loan->copy->book->title}\" has been processed. Thank you!",
            'loan_returned'
        );

        return $loan;
    }

    /** The loan behind a borrowing receipt's barcode (L-000123), or null if the code isn't one. */
    public function findByReceiptCode(string $code): ?Loan
    {
        if (! preg_match('/^L-(\d{1,9})$/i', trim($code), $m)) {
            return null;
        }

        return $this->loans->find((int) $m[1]);
    }

    /**
     * The student has handed the book over, so it is now in the librarian's hands, but it isn't back on the
     * shelf until the librarian has checked it. The return time is fixed here (a late fine stops growing at the
     * moment the book was handed over), the loan becomes "Received", and the copy stays off the shelf.
     */
    public function receiveBook(Loan $loan): Loan
    {
        if ($loan->status === 'Received') {
            throw ValidationException::withMessages([
                'loan' => ['This book was already received. Check it for damage to finish the return.'],
            ]);
        }

        if ($loan->status !== 'Active') {
            throw ValidationException::withMessages([
                'loan' => ['This loan is already closed.'],
            ]);
        }

        DB::transaction(function () use ($loan) {
            $this->loans->update($loan, ['returnDate' => now(), 'status' => 'Received']);
            $this->accruePenalty($loan);
        });

        $this->loans->loadForResponse($loan);

        $this->notifications->send(
            $loan->studentID,
            "We've received \"{$loan->copy->book->title}\". A librarian will check its condition to finish your return.",
            'loan_received'
        );

        return $loan;
    }

    /**
     * After checking the received book: in good condition it goes back on the shelf (and the next person in the
     * reservation queue is told), damaged it stays off the shelf marked as damaged.
     *
     * @param  'good'|'damaged'  $condition
     */
    public function finishReturn(Loan $loan, string $condition, ?string $note = null): Loan
    {
        if ($loan->status !== 'Received') {
            throw ValidationException::withMessages([
                'loan' => ['Only a book that has been received can be checked and finished.'],
            ]);
        }

        DB::transaction(function () use ($loan, $condition) {
            $this->loans->update($loan, ['status' => 'Returned']);
            $this->bookCopies->update($loan->copy, ['status' => $condition === 'damaged' ? 'damaged' : 'available']);
            $this->accruePenalty($loan);

            if ($condition !== 'damaged') {
                $this->reservationService->notifyNextInQueue($loan->copy->bookID);
            }
        });

        $this->loans->loadForResponse($loan);

        $this->achievements->evaluateForStudent($loan->student);

        $title = $loan->copy->book->title;
        $this->notifications->send(
            $loan->studentID,
            $condition === 'damaged'
                ? "\"{$title}\" was returned with damage" . ($note ? ": {$note}" : '') . '. Please visit the library desk.'
                : "Your return of \"{$title}\" has been checked. Thank you!",
            $condition === 'damaged' ? 'loan_returned_damaged' : 'loan_returned'
        );

        return $loan;
    }

    public function studentSubmitSelfReturn(Loan $loan, int $studentID): SelfReturnReport
    {
        if ($loan->studentID !== $studentID) {
            throw new AuthorizationException('This loan does not belong to you.');
        }

        $report = $this->submitSelfReturn($loan);

        $loan->load(['student.user', 'copy.book']);

        $this->notifications->notifyAllLibrarians(
            "{$loan->student->user->fullName} reported returning \"{$loan->copy->book->title}\" - please verify.",
            'self_return_reported'
        );

        return $report;
    }

    public function pendingSelfReturnReports(): Collection
    {
        return $this->selfReturnReports->pendingWithRelations();
    }

    /**
     * Recomputes penalty amounts for every active overdue loan, and alerts
     * the student the first time each loan goes overdue. Driven by the
     * `penalties:accrue-overdue` scheduled command.
     */
    public function accrueOverduePenalties(): int
    {
        $overdueLoans = $this->loans->overdueActive();

        foreach ($overdueLoans as $loan) {
            $this->accruePenalty($loan);

            if (! $loan->overdueNotifiedAt) {
                $this->notifications->send(
                    $loan->studentID,
                    "\"{$loan->copy->book->title}\" is now overdue. Please return it as soon as possible to stop further penalties from accruing.",
                    'loan_overdue'
                );

                $this->loans->update($loan, ['overdueNotifiedAt' => now()]);
            }
        }

        return $overdueLoans->count();
    }

    /**
     * Notifies students whose active loans are due within the next
     * $windowHours (once per loan). Driven by the `loans:notify-due-soon`
     * scheduled command.
     */
    public function notifyDueSoonLoans(int $windowHours = 24): int
    {
        $dueSoonLoans = $this->loans->dueSoonNotUnnotified($windowHours);

        foreach ($dueSoonLoans as $loan) {
            $this->notifications->send(
                $loan->studentID,
                "\"{$loan->copy->book->title}\" is due on {$loan->dueDate->format('M d, Y g:i A')} - return it soon to avoid a penalty.",
                'loan_due_soon'
            );

            $this->loans->update($loan, ['dueSoonNotifiedAt' => now()]);
        }

        return $dueSoonLoans->count();
    }

    /**
     * What the "Verify" / "Reject" window shows for a pending self-return report: the same loan, book, student and
     * late-fee picture as returning the book at the desk, plus when the student reported it.
     *
     * @return array<string, mixed>
     */
    public function selfReturnPreview(SelfReturnReport $report): array
    {
        if ($report->verificationStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'report' => ['This report has already been dealt with.'],
            ]);
        }

        return $this->returnPreview($report->loan, $report);
    }

    /**
     * Verifies a self-return: the book is returned. `$finePaid` is the librarian saying the student paid the late
     * fee at the desk, so the fine is settled in the same step; otherwise it stays on record as unpaid.
     */
    public function verifySelfReturn(SelfReturnReport $report, int $librarianID, bool $finePaid = false): SelfReturnReport
    {
        if ($report->verificationStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'report' => ["Only a 'Pending' self-return report can be verified."],
            ]);
        }

        DB::transaction(function () use ($report, $librarianID, $finePaid) {
            $this->selfReturnReports->update($report, [
                'verifiedByLibrarianID' => $librarianID,
                'verificationStatus'    => 'Verified',
            ]);
            $penalty = $this->markReturned($report->loan);

            if ($finePaid && $penalty && $penalty->paymentStatus !== 'Paid') {
                $this->penalties->update($penalty, ['paymentStatus' => 'Paid', 'settledAt' => now()]);
            }
        });

        $this->selfReturnReports->loadForResponse($report);

        $this->achievements->evaluateForStudent($report->loan->student);

        $this->notifications->send(
            $report->loan->studentID,
            "Your return of \"{$report->loan->copy->book->title}\" has been verified. Thank you!",
            'self_return_verified'
        );

        return $report;
    }

    public function rejectSelfReturn(SelfReturnReport $report, int $librarianID): SelfReturnReport
    {
        if ($report->verificationStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'report' => ["Only a 'Pending' self-return report can be rejected."],
            ]);
        }

        $this->selfReturnReports->update($report, [
            'verifiedByLibrarianID' => $librarianID,
            'verificationStatus'    => 'Rejected',
        ]);

        $this->selfReturnReports->loadForResponse($report);

        $this->notifications->send(
            $report->loan->studentID,
            "Your self-return report for \"{$report->loan->copy->book->title}\" could not be verified. Please return the physical copy, or try reporting again after 6 hours.",
            'self_return_rejected'
        );

        return $report;
    }

    private function submitSelfReturn(Loan $loan): SelfReturnReport
    {
        if ($loan->status !== 'Active') {
            throw ValidationException::withMessages(['loan' => ['Only an active loan can be self-returned.']]);
        }

        $existing = $loan->selfReturnReport;

        if ($existing && $existing->verificationStatus === 'Pending') {
            throw ValidationException::withMessages([
                'loan' => ['A self-return report for this loan is already pending verification.'],
            ]);
        }

        if ($existing && $existing->verificationStatus === 'Rejected') {
            $cooldownEnds = $existing->updated_at->addHours(6);

            if (now()->lessThan($cooldownEnds)) {
                throw ValidationException::withMessages([
                    'loan' => ["You can report this return again after {$cooldownEnds->format('M d, Y g:i A')}."],
                ]);
            }

            return $this->selfReturnReports->update($existing, [
                'verifiedByLibrarianID' => null,
                'reportedAt'            => now(),
                'verificationStatus'    => 'Pending',
            ])->fresh();
        }

        return $this->selfReturnReports->create([
            'uuid'               => Str::uuid(),
            'loanID'             => $loan->loanID,
            'reportedAt'         => now(),
            'verificationStatus' => 'Pending',
        ]);
    }

    /**
     * Marks a loan returned, frees its copy, does a final penalty accrual
     * (locking in the fine as of the exact return moment), and wakes up the
     * next Waiting reservation for the title if a copy just freed up.
     */
    private function markReturned(Loan $loan): ?Penalty
    {
        $this->loans->update($loan, ['returnDate' => $loan->returnDate ?? now(), 'status' => 'Returned']);
        $this->bookCopies->update($loan->copy, ['status' => 'available']);
        $penalty = $this->accruePenalty($loan);
        $this->reservationService->notifyNextInQueue($loan->copy->bookID);

        return $penalty;
    }

    private function accruePenalty(Loan $loan): ?Penalty
    {
        $book = $loan->copy->book;
        $rule = $this->penalties->ruleForArea($book->areaOfLibrary);

        if (! $rule) {
            return null; // area isn't configured for penalties (e.g. not loanable)
        }

        $endTime = $loan->returnDate ?? now();
        $elapsedUnits = $this->lateUnits($loan->dueDate, $endTime, $rule);

        if ($elapsedUnits <= 0) {
            return null; // not actually overdue
        }

        $penalty = $this->penalties->firstOrNewForLoan($loan->loanID);
        $penalty->uuid ??= (string) Str::uuid();
        $penalty->penaltyTypeID = $rule->penaltyTypeID;
        $penalty->amount = round($rule->rate * $elapsedUnits, 2);
        $penalty->computedAt = now();
        $penalty->paymentStatus ??= 'Unpaid';
        $penalty->save();

        return $penalty;
    }

    /**
     * How many units a fine is charged for: the lateness that is left once the rule's grace period (whole days after
     * the due time) has passed. Within the grace period nothing is charged.
     */
    public function lateUnits(\DateTimeInterface $due, \DateTimeInterface $end, PenaltyRule $rule): int
    {
        $chargeFrom = Carbon::parse($due)->addDays((int) ($rule->gracePeriodDays ?? 0));

        return $this->elapsedUnits($chargeFrom, $end, $rule->rateUnit);
    }

    public function elapsedUnits(\DateTimeInterface $due, \DateTimeInterface $end, string $unit): int
    {
        $due = Carbon::parse($due);
        $end = Carbon::parse($end);

        if ($end->lessThanOrEqualTo($due)) {
            return 0;
        }

        $minutes = $due->diffInMinutes($end);

        return match ($unit) {
            'hour'  => (int) ceil($minutes / 60),
            default => (int) ceil($minutes / (60 * 24)), // 'day'
        };
    }

    private function loanRules(Book $book): array
    {
        $period = $this->loanPeriods->find($book->areaOfLibrary);

        if (! $period) {
            return [];
        }

        return [
            'loanable'     => $period->loanable,
            'period_value' => $period->periodValue,
            'period_unit'  => $period->periodUnit,
        ];
    }

    private function assertLoanable(Book $book): void
    {
        $rules = $this->loanRules($book);

        if (empty($rules['loanable'] ?? false)) {
            throw ValidationException::withMessages([
                'copyID' => ["Books under '{$book->areaOfLibrary}' are for library use only and cannot be loaned out."],
            ]);
        }
    }

    private function computeDueDate(Book $book, ?\DateTimeInterface $from = null): ?Carbon
    {
        $rules = $this->loanRules($book);

        if (empty($rules['loanable'])) {
            return null;
        }

        $from = $from ? Carbon::instance($from) : now();

        return match ($rules['period_unit']) {
            'days'      => $from->copy()->addDays($rules['period_value']),
            'overnight' => $from->copy()->addDay(),
            default     => $from->copy()->addDays($rules['period_value'] ?? 0),
        };
    }
}
