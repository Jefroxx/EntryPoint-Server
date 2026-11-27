<?php

namespace Database\Seeders;

use App\Models\BookCopy;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\Reservation;
use App\Models\SelfReturnReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Circulation for the demo: every state a loan, a fine, a reservation and a self-return report can be in, once,
 * on the books of the demo catalog (named by accession number) and the demo students (see StudentSeeder).
 */
class LoanSeeder extends Seeder
{
    private string $librarianID;

    public function run(): void
    {
        $this->librarianID = (string) Librarian::where('role', 'librarian')->value('librarianID');
        $s = fn (string $key) => StudentSeeder::find($key);
        $faculty = User::where('email', 'roberto.alcantara@davao.sti.edu.ph')->first()?->student;
        $today = Carbon::today();

        // Bea, the top reader: four books borrowed and returned on time, and one out now (due in five days).
        foreach ([4036 => 48, 4037 => 35, 3476 => 21, 3478 => 12] as $acc => $daysAgo) {
            $out = $today->copy()->subDays($daysAgo)->setTime(10, 15);
            $this->loan($s('bea'), $acc, $out, $out->copy()->addDays(3), $out->copy()->addDays(2)->setTime(14, 0));
        }
        $this->loan($s('bea'), 3480, $today->copy()->subDays(2)->setTime(9, 40), $today->copy()->addDays(5)->setTime(17, 0));

        // Carlo: a book five days overdue (the fine grows each day), and a late fee from an earlier book, unpaid.
        $this->loan($s('carlo'), 4039, $today->copy()->subDays(8)->setTime(13, 5), $today->copy()->subDays(5)->setTime(17, 0));
        $late = $this->loan($s('carlo'), 4547, $today->copy()->subDays(30)->setTime(11, 0), $today->copy()->subDays(27)->setTime(17, 0), $today->copy()->subDays(24)->setTime(9, 30));
        $this->lateFee($late, 10.00, paid: false);

        // Diane: a book due today, a late fee she paid at the cashier, and a reservation accepted and waiting at the desk.
        $this->loan($s('diane'), 4553, $today->copy()->subDays(3)->setTime(15, 20), $today->copy()->setTime(17, 0));
        $paid = $this->loan($s('diane'), 4697, $today->copy()->subDays(40)->setTime(10, 0), $today->copy()->subDays(37)->setTime(17, 0), $today->copy()->subDays(35)->setTime(8, 45));
        $this->lateFee($paid, 5.00, paid: true);
        $this->reservation($s('diane'), 2830, 'Accepted', $today->copy()->subDays(1)->setTime(16, 0), accepted: $today->copy()->setTime(8, 30));

        // Enzo: reported a book returned while the desk was unattended (waiting for a librarian to check the shelf).
        $reported = $this->loan($s('enzo'), 3482, $today->copy()->subDays(4)->setTime(10, 30), $today->copy()->addDays(3)->setTime(17, 0));
        SelfReturnReport::create([
            'uuid' => Str::uuid(), 'loanID' => $reported->loanID, 'reportedAt' => $today->copy()->setTime(7, 50), 'verificationStatus' => 'Pending',
        ]);

        // Basic Calculus has one copy, out with a faculty member: Enzo and Gab wait for it, first come, first served.
        if ($faculty) {
            $this->loan($faculty, 3481, $today->copy()->subDays(1)->setTime(14, 0), $today->copy()->addDays(6)->setTime(17, 0));
        }
        $this->reservation($s('enzo'), 3481, 'Waiting', $today->copy()->subDays(1)->setTime(15, 10));
        $this->reservation($s('gab'), 3481, 'Waiting', $today->copy()->setTime(9, 5));

        // Faith: lost a book. The copy is marked lost and she is charged its price, not paid yet (or a replacement).
        $lost = $this->loan($s('faith'), 3473, $today->copy()->subDays(20)->setTime(11, 30), $today->copy()->subDays(17)->setTime(17, 0), $today->copy()->subDays(6)->setTime(10, 0));
        BookCopy::where('copyID', $lost->copyID)->update(['status' => 'lost']);
        Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $lost->loanID, 'studentID' => $lost->studentID, 'copyID' => $lost->copyID,
            'penaltyTypeID' => PenaltyType::where('kind', 'lost')->value('penaltyTypeID'),
            'amount' => 695.00, 'computedAt' => $today->copy()->subDays(6)->setTime(10, 0), 'paymentStatus' => 'Unpaid',
            'reason' => 'Lost by the student',
        ]);

        // Gab: handed a book in this morning; the librarian still has to check it for damage to finish the return.
        $this->loan($s('gab'), 4587, $today->copy()->subDays(3)->setTime(9, 0), $today->copy()->addDay()->setTime(17, 0), $today->copy()->setTime(8, 10), 'Received');

        // Reservations finished earlier: one picked up (Bea), one turned down (Carlo).
        $this->reservation($s('bea'), 3476, 'Fulfilled', $today->copy()->subDays(22)->setTime(9, 0), accepted: $today->copy()->subDays(21)->setTime(10, 0));
        $this->reservation($s('carlo'), 4988, 'Rejected', $today->copy()->subDays(9)->setTime(13, 0));
    }

    /** A loan of the copy with that accession number. No return date: out now; with one: returned (or handed in). */
    private function loan(Student $student, int $accession, Carbon $out, Carbon $due, ?Carbon $returned = null, ?string $status = null): Loan
    {
        $copy = BookCopy::with('book')->where('accessionNumber', $accession)->firstOrFail();
        $status ??= $returned ? 'Returned' : 'Active';

        if (in_array($status, ['Active', 'Received'], true)) {
            $copy->update(['status' => 'borrowed']);
        }

        return Loan::create([
            'uuid'         => Str::uuid(),
            'studentID'    => $student->studentID,
            'copyID'       => $copy->copyID,
            'loanType'     => $copy->book->areaOfLibrary,
            'checkoutDate' => $out,
            'dueDate'      => $due,
            'returnDate'   => $returned,
            'status'       => $status,
        ]);
    }

    private function lateFee(Loan $loan, float $amount, bool $paid): void
    {
        Penalty::create([
            'uuid'          => Str::uuid(),
            'loanID'        => $loan->loanID,
            'studentID'     => $loan->studentID,
            'copyID'        => $loan->copyID,
            'penaltyTypeID' => PenaltyType::where('kind', 'overdue')->where('areaOfLibrary', $loan->loanType)->value('penaltyTypeID'),
            'amount'        => $amount,
            'computedAt'    => $loan->returnDate,
            'settledAt'     => $paid ? $loan->returnDate : null,
            'paymentStatus' => $paid ? 'Paid' : 'Unpaid',
            'receiptNumber' => $paid ? 'OR-2026-0412' : null,
        ]);
    }

    private function reservation(Student $student, int $accession, string $status, Carbon $at, ?Carbon $accepted = null): void
    {
        Reservation::create([
            'uuid'       => Str::uuid(),
            'studentID'  => $student->studentID,
            'bookID'     => BookCopy::where('accessionNumber', $accession)->value('bookID'),
            'status'     => $status,
            'reservedAt' => $at,
            'acceptedAt' => $accepted,
        ]);
    }
}
