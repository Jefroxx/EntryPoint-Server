<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The penalty for a lost or damaged book. The librarian issues it from the Circulation "Lost and damaged" tab, for a
 * loan or for a student and a copy (a book can be lost inside the library, with no loan behind it). The student then
 * either pays the amount at the desk, which is recorded with the cashier's receipt number, or replaces the book.
 * Either way the old copy leaves the catalog.
 */
class CopyPenaltyService
{
    public function __construct(
        private PenaltyRepositoryInterface $penalties,
        private BookCopyRepositoryInterface $bookCopies,
        private CatalogService $catalog,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Charges the student for the copy, marks the copy lost or damaged, and (if the book was still out on loan)
     * closes that loan, since the book is not coming back.
     *
     * Give either `$loan` (its student and copy are used) or `$studentID` and `$copy` (no loan needed).
     */
    public function issue(string $kind, float $amount, ?Loan $loan, ?int $studentID, ?BookCopy $copy): Penalty
    {
        if (! in_array($kind, Penalty::COPY_CATEGORIES, true)) {
            throw ValidationException::withMessages(['type' => ['A penalty is for a book that is lost or damaged.']]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['The penalty must be more than zero.']]);
        }

        $copy = $loan?->copy ?? $copy;
        $studentID = $loan?->studentID ?? $studentID;

        if (! $copy || ! $studentID) {
            throw ValidationException::withMessages(['loan' => ['Choose a loan, or a student and a copy.']]);
        }

        $penalty = DB::transaction(function () use ($kind, $amount, $loan, $studentID, $copy) {
            $copy = $this->bookCopies->lockForUpdateWithBook($copy->copyID);

            if ($copy->status === 'retired') {
                throw ValidationException::withMessages([
                    'copy' => ["Accession no. {$copy->accessionNumber} has already been removed from the catalog."],
                ]);
            }

            if ($this->penalties->openForCopy($copy->copyID)) {
                throw ValidationException::withMessages([
                    'copy' => ["Accession no. {$copy->accessionNumber} already has an unpaid penalty."],
                ]);
            }

            // Who has the copy out right now, if anyone. It can only be marked lost or damaged by the student holding it.
            $holding = Loan::where('copyID', $copy->copyID)->whereIn('status', ['Active', 'Received'])->first();

            if ($holding && $holding->studentID !== $studentID) {
                throw ValidationException::withMessages([
                    'copy' => ["Accession no. {$copy->accessionNumber} is out on loan to someone else."],
                ]);
            }

            if ($holding) {
                $loan ??= $holding;
                $this->closeLoan($holding);
            }

            $this->bookCopies->update($copy, ['status' => $kind]);

            return $this->penalties->create([
                'uuid'          => (string) Str::uuid(),
                'loanID'        => $loan?->loanID,
                'studentID'     => $studentID,
                'copyID'        => $copy->copyID,
                'penaltyTypeID' => PenaltyType::where('category', $kind)->firstOrFail()->penaltyTypeID,
                'amount'        => round($amount, 2),
                'computedAt'    => now(),
                'paymentStatus' => 'Unpaid',
            ]);
        });

        $title = $penalty->copy->book()->withTrashed()->first()?->title ?? 'a book';

        $this->notifications->send(
            $studentID,
            sprintf(
                'The book "%s" (accession no. %s) was marked %s. You can pay %s at the desk, or replace the book. Please see the librarian.',
                $title, $penalty->copy->accessionNumber, $kind, '₱' . number_format($penalty->amount, 2)
            ),
            'copy_penalty_issued'
        );

        return $penalty->load(Penalty::listingRelations());
    }

    /** The student brought a replacement: close the penalty without payment, add the new copy and retire the old one. */
    public function replace(Penalty $penalty): array
    {
        $this->assertOpenCopyPenalty($penalty, 'replaced');

        $old = $penalty->copy;
        $book = $old->book()->withTrashed()->first();

        $replacement = DB::transaction(function () use ($penalty, $old, $book) {
            $this->penalties->update($penalty, ['paymentStatus' => 'Replaced', 'settledAt' => now()]);

            $new = $this->catalog->addCopies($book, 1, [
                'sourceOfFund' => 'Replacement by student',
                'remarks'      => "Replaces accession no. {$old->accessionNumber} ({$penalty->penaltyType->category}).",
            ])->first();

            $this->catalog->removeCopy($old, ucfirst($penalty->penaltyType->category), "Replaced by accession no. {$new->accessionNumber}.");

            return $new;
        });

        $this->notifications->send(
            $penalty->studentID,
            "Thank you. Your replacement for \"{$book->title}\" was received and the penalty is closed.",
            'copy_penalty_replaced'
        );

        return ['penalty' => $penalty->fresh(Penalty::listingRelations()), 'replacement' => $replacement];
    }

    /** The book turned up (or was repaired): cancel the penalty and put the copy back on the shelf. */
    public function cancel(Penalty $penalty): void
    {
        $this->assertOpenCopyPenalty($penalty, 'cancelled');

        $copy = $penalty->copy;
        $title = $copy->book()->withTrashed()->first()?->title ?? 'the book';
        $studentID = $penalty->studentID;

        DB::transaction(function () use ($penalty, $copy) {
            $penalty->delete();

            if (in_array($copy->status, Penalty::COPY_CATEGORIES, true)) {
                $this->bookCopies->update($copy, ['status' => 'available']);
            }
        });

        $this->notifications->send($studentID, "Your penalty for \"{$title}\" was cancelled. You no longer owe anything for it.", 'copy_penalty_cancelled');
    }

    /** Called once the penalty is paid: the lost or damaged copy leaves the catalog. */
    public function retireAfterPayment(Penalty $penalty): void
    {
        $penalty->loadMissing('penaltyType', 'copy');

        if ($penalty->isForCopy() && $penalty->copy && $penalty->copy->status !== 'retired') {
            $this->catalog->removeCopy($penalty->copy, ucfirst($penalty->penaltyType->category), 'Paid by the student.');
        }
    }

    /** The book is gone, so its loan ends here (no late fee: the penalty is for the book itself). */
    private function closeLoan(Loan $loan): void
    {
        if ($loan->selfReturnReport?->verificationStatus === 'Pending') {
            throw ValidationException::withMessages([
                'loan' => ['This loan has a self-return report waiting. Verify or reject it first.'],
            ]);
        }

        $loan->update(['status' => 'Returned', 'returnDate' => now()]);
    }

    private function assertOpenCopyPenalty(Penalty $penalty, string $verb): void
    {
        $penalty->loadMissing('penaltyType', 'copy');

        if (! $penalty->isForCopy()) {
            throw ValidationException::withMessages(['penalty' => ["Only a penalty for a lost or damaged book can be {$verb}."]]);
        }

        if ($penalty->paymentStatus !== 'Unpaid') {
            throw ValidationException::withMessages(['penalty' => ['This penalty is already ' . strtolower($penalty->paymentStatus) . '.']]);
        }
    }
}
