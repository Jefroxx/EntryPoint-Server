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
    /** How long a student waits after a rejected self-return before they can report it again. */
    public const SELF_RETURN_COOLDOWN_HOURS = 6;

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
        private CopyPenaltyService $copyPenalties,
        private StockLogService $stockLog,
    ) {
    }

    public function index(?string $search, ?string $status, int $perPage, ?string $area = null, bool $hideReceived = false): LengthAwarePaginator
    {
        // An overdue loan says what it has cost so far (what returning it right now would charge).
        // The fine rule is looked up once per area for the whole page, not once per loan (it was 25 of the list's 52 queries).
        $rules = [];

        return $this->loans->paginate($search, $status, $perPage, $area, $hideReceived)->through(function (Loan $loan) use (&$rules) {
            $loan->setAttribute('fineSoFar', $this->fineSoFar($loan, $rules));

            return $loan;
        });
    }

    /** @param array<string, ?PenaltyRule> $rules the rules already looked up during this request, by area */
    private function fineSoFar(Loan $loan, array &$rules = []): ?float
    {
        $end = $this->fineEnd($loan);

        if ($loan->status !== 'Active' || $this->fineClock($loan)->greaterThanOrEqualTo($end)) {
            return null;
        }

        $areaKey = $loan->copy?->book?->areaOfLibrary ?? $loan->loanType;
        $rule = array_key_exists($areaKey, $rules) ? $rules[$areaKey] : ($rules[$areaKey] = $this->penalties->ruleForArea($areaKey));
        $units = $rule ? $this->lateUnits($this->fineClock($loan), $end, $rule) : 0;

        return $units > 0 ? round($rule->rate * $units, 2) : null;
    }

    /**
     * @return array{active: int, dueSoon: int, overdue: int}
     */
    public function stats(): array
    {
        return $this->loans->stats();
    }

    /** `$kind`: 'copy' for the Lost & Damaged tab, 'late' for the Penalties tab, null for both. */
    public function penaltiesIndex(?string $search, ?string $status, int $perPage, ?string $kind = null, ?string $area = null, ?string $type = null): LengthAwarePaginator
    {
        // penaltyType -> penalty_type: matches the frontend's naming, kept
        // out of the model layer since it's purely a response-shape concern.
        return $this->penalties->paginate($search, $status, $perPage, $kind, $area, $type)->through(function (Penalty $penalty) {
            $array = $penalty->toArray();
            $array['penalty_type'] = $penalty->penaltyType;
            unset($array['penaltyType']);

            return $array;
        });
    }

    /**
     * @return array{unpaidCount: int, unpaidTotal: float, paidCount: int, replacedCount: int, longUnpaidCount: int, longUnpaidDays: int}
     */
    public function penaltyStats(?string $kind = null): array
    {
        return $this->penalties->stats($kind);
    }

    public function settlePenalty(Penalty $penalty, ?string $receiptNumber = null): Penalty
    {
        if ($penalty->paymentStatus !== 'Unpaid') {
            throw ValidationException::withMessages([
                'penalty' => ['This penalty is already ' . strtolower($penalty->paymentStatus) . '.'],
            ]);
        }

        DB::transaction(function () use ($penalty, $receiptNumber) {
            $this->penalties->update($penalty, [
                'paymentStatus' => 'Paid',
                'settledAt'     => now(),
                'receiptNumber' => $receiptNumber,
            ]);

            // Paying for a lost or damaged book ends that copy's life in the catalog.
            $this->copyPenalties->retireAfterPayment($penalty);
        });

        return $penalty->fresh(Penalty::listingRelations());
    }

    /**
     * The librarian corrects a fine that is still unpaid: a note on it, and (once its amount is settled) the amount.
     * A late fee that is still running up can't have its amount typed over: the hourly job would write it back.
     */
    public function updatePenalty(Penalty $penalty, array $data): Penalty
    {
        $penalty->loadMissing('loan', 'penaltyType');

        if ($penalty->paymentStatus !== 'Unpaid') {
            throw ValidationException::withMessages(['penalty' => ['Only an unpaid penalty can be edited.']]);
        }

        $changes = [];

        if (array_key_exists('amount', $data) && $data['amount'] !== null && (float) $data['amount'] !== (float) $penalty->amount) {
            if (! $penalty->isForCopy() && $penalty->loan && $penalty->loan->status !== 'Returned') {
                throw ValidationException::withMessages(['amount' => ['This fine is still running up until the book is back, so its amount can\'t be changed yet.']]);
            }
            $changes['amount'] = round((float) $data['amount'], 2);
        }

        if (array_key_exists('reason', $data)) {
            $changes['reason'] = trim((string) $data['reason']) !== '' ? trim($data['reason']) : null;
        }

        if ($changes) {
            $this->penalties->update($penalty, $changes);

            if (isset($changes['amount'])) {
                $title = $penalty->copy?->book()->withTrashed()->first()?->title ?? 'a book';
                $this->notifications->send($penalty->studentID, "The penalty for \"{$title}\" was changed to ₱" . number_format($changes['amount'], 2) . '.', 'penalty_amount_changed');
            }
        }

        return $penalty->fresh(Penalty::listingRelations());
    }

    /** The librarian forgives a late fee, with a reason. Nothing is owed any more and the student is told. */
    public function waivePenalty(Penalty $penalty, string $reason): Penalty
    {
        $penalty->loadMissing('penaltyType');

        if ($penalty->paymentStatus !== 'Unpaid') {
            throw ValidationException::withMessages(['penalty' => ['Only an unpaid fine can be waived.']]);
        }

        if ($penalty->isForCopy()) {
            throw ValidationException::withMessages(['penalty' => ['A lost or damaged book is not waived: mark the book found or repaired to cancel the penalty instead.']]);
        }

        $this->penalties->update($penalty, ['paymentStatus' => 'Waived', 'settledAt' => now(), 'reason' => trim($reason)]);

        $title = $penalty->copy?->book()->withTrashed()->first()?->title ?? 'a book';
        $this->notifications->send($penalty->studentID, "Your late fee for \"{$title}\" was waived. You no longer owe it.", 'penalty_waived');

        return $penalty->fresh(Penalty::listingRelations());
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

            $this->assertCanBorrow((int) $validated['studentID'], $reservation !== null, (int) $copy->bookID);

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
     * Who may borrow at the desk: an approved account, with no overdue book, no unpaid penalty, and a free slot. A
     * student has three slots in all (loans and open reservations together, the same as when reserving); collecting
     * a reservation turns one of those slots into a loan, so it never needs a free one.
     */
    private function assertCanBorrow(int $studentID, bool $collectingReservation, int $bookID): void
    {
        $student = \App\Models\Student::find($studentID);

        if (! $student || $student->registrationStatus !== 'approved') {
            throw ValidationException::withMessages(['studentID' => ["This account isn't approved, so it can't borrow books."]]);
        }

        $overdue = \App\Models\Loan::where('studentID', $studentID)->where('status', 'Active')->where('dueDate', '<', now())->count();
        if ($overdue > 0) {
            throw ValidationException::withMessages(['studentID' => ["They have {$overdue} overdue " . ($overdue === 1 ? 'book' : 'books') . '. Return ' . ($overdue === 1 ? 'it' : 'them') . ' first.']]);
        }

        if ($this->penalties->unpaidTotalForStudent($studentID) > 0) {
            throw ValidationException::withMessages(['studentID' => ['They have an unpaid penalty. Settle it first (Circulation > Penalties).']]);
        }

        // One copy of a title at a time: they can't have a second copy of a book they still have out.
        $sameBook = \App\Models\Loan::where('studentID', $studentID)->whereIn('status', ['Active', 'Received'])
            ->whereHas('copy', fn ($copy) => $copy->where('bookID', $bookID))->exists();
        if ($sameBook) {
            throw ValidationException::withMessages(['studentID' => ['They already have a copy of this book. A student can have only one copy of a title at a time.']]);
        }

        $max = AppSettings::maxBooks();
        $slotsUsed = $this->loans->activeCountForStudent($studentID) + $this->reservations->activeCountForStudent($studentID);

        if ($collectingReservation ? $slotsUsed > $max : $slotsUsed >= $max) {
            throw ValidationException::withMessages(['studentID' => ["They already have {$max} " . ($max === 1 ? 'book' : 'books') . " out or reserved, which is the most at a time."]]);
        }
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
                'memberIDNumber' => $loan->student->memberIDNumber,
                'program'         => $loan->student->academicProgram,
            ],
            'book' => [
                ...$book->only([
                    'title', 'isbn', 'areaOfLibrary', 'publicationYear', 'volume', 'edition', 'pageCount', 'publisher',
                ]),
                // The slip goes to the student, so an unchecked (guessed) number is left off it.
                'callNumber'      => $book->publicCallNumber(),
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
        // Returning at the desk a book the student already reported is judged as of their report too.
        $report ??= $this->pendingReport($loan);

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
        // A self-return report is judged as of the moment the student reported, not the moment it is looked at.
        $end = $report?->reportedAt ?? now();
        $unitsLate = $rule ? $this->lateUnits($this->fineClock($loan), $end, $rule) : 0;

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
                'overdue'    => $this->fineClock($loan)->lt($end),
            ],
            'book' => [
                'title'           => $book->title,
                'authors'         => $book->authors->pluck('name')->values(),
                'isbn'            => $book->isbn,
                'callNumber'      => $book->callNumber,
                'areaOfLibrary'   => $book->areaOfLibrary,
                'accessionNumber' => $copy->accessionNumber,
                'barcodeValue'    => $copy->barcodeValue,
            ],
            'student' => [
                'name'            => $user->fullName,
                'memberIDNumber' => $student->memberIDNumber,
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
    public function returnBook(Loan $loan, bool $finePaid = false, ?string $receiptNumber = null, ?int $librarianID = null): Loan
    {
        // The student already reported this book back: returning it at the desk settles that report, so the fine is
        // judged as of when they reported it, exactly as if the report had been verified.
        $pending = $this->pendingReport($loan);
        if ($pending && $loan->status === 'Active' && $librarianID) {
            $this->verifySelfReturn($pending, $librarianID, $finePaid, $receiptNumber);

            $loan->refresh();
            $this->loans->loadForResponse($loan);

            return $loan;
        }

        // A book already handed over and waiting to be checked finishes as "in good condition".
        if ($loan->status === 'Received') {
            return $this->finishReturn($loan, 'good');
        }

        if ($loan->status !== 'Active') {
            throw ValidationException::withMessages([
                'loan' => ["Only an 'Active' loan can be returned."],
            ]);
        }

        DB::transaction(function () use ($loan, $finePaid, $receiptNumber) {
            $penalty = $this->markReturned($loan);

            if ($finePaid && $penalty && $penalty->paymentStatus !== 'Paid') {
                $this->penalties->update($penalty, ['paymentStatus' => 'Paid', 'settledAt' => now(), 'receiptNumber' => $receiptNumber]);
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
            $before = $loan->copy->status;
            $after = $condition === 'damaged' ? 'damaged' : 'available';
            $this->bookCopies->update($loan->copy, ['status' => $after]);
            // Only a copy that came back damaged is a change to the copy; coming back on the shelf is loan activity.
            if ($after === 'damaged') {
                $this->stockLog->statusChanged($loan->copy->book, $loan->copy, $before, 'damaged');
            }
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
            "{$loan->student->user->fullName} returned \"{$loan->copy->book->title}\" - please verify.",
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
    public function verifySelfReturn(SelfReturnReport $report, int $librarianID, bool $finePaid = false, ?string $receiptNumber = null): SelfReturnReport
    {
        if ($report->verificationStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'report' => ["Only a 'Pending' self-return report can be verified."],
            ]);
        }

        DB::transaction(function () use ($report, $librarianID, $finePaid, $receiptNumber) {
            $this->selfReturnReports->update($report, [
                'verifiedByLibrarianID' => $librarianID,
                'verificationStatus'    => 'Verified',
            ]);
            // The book came back when the student handed it in, not when the librarian got round to checking: a fine
            // stops there, and a book reported before it was due is never late, however long the check took.
            $loan = $report->loan;
            $this->loans->update($loan, ['returnDate' => $loan->returnDate ?? $report->reportedAt]);
            $penalty = $this->markReturned($loan);

            if ($finePaid && $penalty && $penalty->paymentStatus !== 'Paid') {
                $this->penalties->update($penalty, ['paymentStatus' => 'Paid', 'settledAt' => now(), 'receiptNumber' => $receiptNumber]);
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

    public function rejectSelfReturn(SelfReturnReport $report, int $librarianID, string $reason): SelfReturnReport
    {
        if ($report->verificationStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'report' => ["Only a 'Pending' self-return report can be rejected."],
            ]);
        }

        DB::transaction(function () use ($report, $librarianID, $reason) {
            $this->selfReturnReports->update($report, [
                'verifiedByLibrarianID' => $librarianID,
                'verificationStatus'    => 'Rejected',
                'rejectionReason'       => $reason,
            ]);

            // The student reported it before it was due, so nothing was late until now: the late fee starts counting
            // from this rejection. (A report made once the book was already overdue changes nothing: it stays late.)
            $loan = $report->loan;
            if ($report->reportedAt->lessThanOrEqualTo($loan->dueDate)) {
                $this->loans->update($loan, ['fineStartsAt' => now()]);
            }
        });

        $this->selfReturnReports->loadForResponse($report);

        $this->notifications->send(
            $report->loan->studentID,
            "Your self-return report for \"{$report->loan->copy->book->title}\" could not be verified. Reason: {$reason} Please return the physical copy, or try reporting again after 6 hours.",
            'self_return_rejected'
        );

        return $report;
    }

    /** When a rejected report can be sent again (counted from the moment it was rejected). */
    public function selfReturnCooldownEnds(SelfReturnReport $report): Carbon
    {
        return $report->updated_at->copy()->addHours(self::SELF_RETURN_COOLDOWN_HOURS);
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
            $cooldownEnds = $this->selfReturnCooldownEnds($existing);

            if (now()->lessThan($cooldownEnds)) {
                throw ValidationException::withMessages([
                    'loan' => ["You can report this return again after {$cooldownEnds->format('M d, Y g:i A')}."],
                ]);
            }

            return $this->selfReturnReports->update($existing, [
                'verifiedByLibrarianID' => null,
                'reportedAt'            => now(),
                'verificationStatus'    => 'Pending',
                'rejectionReason'       => null,
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
        $elapsedUnits = $this->lateUnits($this->fineClock($loan), $endTime, $rule);

        if ($elapsedUnits <= 0) {
            return null; // not actually overdue
        }

        $penalty = $this->penalties->firstOrNewForLoan($loan->loanID);

        // Once a fine is paid, replaced or waived it is settled: the clock running on never changes it.
        if ($penalty->exists && $penalty->paymentStatus !== 'Unpaid') {
            return $penalty;
        }

        $penalty->uuid ??= (string) Str::uuid();
        $penalty->studentID = $loan->studentID;
        $penalty->copyID = $loan->copyID;
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
    /** The student's self-return report for this loan, if it is still waiting to be checked. */
    private function pendingReport(Loan $loan): ?SelfReturnReport
    {
        $report = $loan->selfReturnReport;

        return $report && $report->verificationStatus === 'Pending' ? $report : null;
    }

    /**
     * When this loan's late fee starts counting: the due time, or later if an on-time self-return report was rejected
     * (the book was not really handed in, so the clock starts at the rejection).
     */
    private function fineClock(Loan $loan): Carbon
    {
        $due = Carbon::parse($loan->dueDate);

        return $loan->fineStartsAt && $loan->fineStartsAt->gt($due) ? Carbon::parse($loan->fineStartsAt) : $due;
    }

    /** Where a still-out loan's fine counts up to: now, or the moment the student reported it while that report waits. */
    private function fineEnd(Loan $loan): Carbon
    {
        $report = $loan->selfReturnReport;

        return $report && $report->verificationStatus === 'Pending' ? Carbon::parse($report->reportedAt) : now();
    }

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

    /**
     * What lending this book would mean right now, for the checkout desk to show before anything is saved:
     * whether its area of the library can be loaned out at all, and the date it would fall due if it went out today.
     *
     * @return array{loanable: bool, dueDate: ?string}
     */
    public function loanTerms(Book $book): array
    {
        $loanable = ! empty($this->loanRules($book)['loanable'] ?? false);

        return ['loanable' => $loanable, 'dueDate' => $loanable ? $this->computeDueDate($book)?->toIso8601String() : null];
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
