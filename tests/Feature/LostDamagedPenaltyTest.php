<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\SelfReturnReport;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A lost or damaged book is charged to a student, for a loan or for a student and a copy (a book lost inside the
 * library has no loan). They pay the amount (with the cashier's receipt number) or replace the book, and either way
 * the old copy leaves the catalog.
 */
class LostDamagedPenaltyTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private Student $other;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $this->student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->other = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'circulation', 'cost' => 450]);
    }

    private function copy(string $status = 'available'): BookCopy
    {
        return BookCopy::factory()->create(['bookID' => $this->book->bookID, 'status' => $status]);
    }

    private function loan(BookCopy $copy, string $status = 'Returned', ?Student $student = null): Loan
    {
        return Loan::create([
            'uuid'         => Str::uuid(),
            'studentID'    => ($student ?? $this->student)->studentID,
            'copyID'       => $copy->copyID,
            'loanType'     => 'circulation',
            'checkoutDate' => now()->subDays(20),
            'dueDate'      => now()->subDays(10),
            'returnDate'   => $status === 'Returned' ? now()->subDays(8) : null,
            'status'       => $status,
        ]);
    }

    private function issue(array $body)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/penalties', ['type' => 'lost', 'amount' => 450, ...$body]);
    }

    private function open(string $kind = 'lost'): Penalty
    {
        $copy = $this->copy();

        return Penalty::findOrFail($this->issue(['type' => $kind, 'studentID' => $this->student->studentID, 'copyID' => $copy->copyID])->json('penalty.penaltyID'));
    }

    // ---- Issuing ----

    public function test_a_penalty_for_a_returned_loan_marks_the_copy_and_charges_that_student(): void
    {
        $copy = $this->copy();
        $loan = $this->loan($copy);

        $this->issue(['type' => 'damaged', 'amount' => 300, 'loan' => $loan->uuid])
            ->assertCreated()
            ->assertJsonPath('penalty.amount', '300.00')
            ->assertJsonPath('penalty.paymentStatus', 'Unpaid')
            ->assertJsonPath('penalty.penalty_type.category', 'damaged')
            ->assertJsonPath('penalty.loanID', $loan->loanID)
            ->assertJsonPath('penalty.student.studentID', $this->student->studentID)
            ->assertJsonPath('penalty.copy.copyID', $copy->copyID);

        $this->assertSame('damaged', $copy->fresh()->status);
        $this->assertDatabaseHas('notifications', ['userID' => $this->student->studentID, 'type' => 'copy_penalty_issued']);
    }

    public function test_a_penalty_for_a_loan_still_out_closes_the_loan(): void
    {
        $copy = $this->copy('borrowed');
        $loan = $this->loan($copy, 'Active');

        $this->issue(['loan' => $loan->uuid])->assertCreated();

        $this->assertSame('Returned', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->returnDate);
        $this->assertSame('lost', $copy->fresh()->status);
        // The penalty is for the book itself: no late fee on top.
        $this->assertSame(1, Penalty::where('loanID', $loan->loanID)->count());
    }

    public function test_a_book_lost_in_the_library_can_be_charged_with_no_loan(): void
    {
        $copy = $this->copy();

        $this->issue(['type' => 'damaged', 'studentID' => $this->student->studentID, 'copyID' => $copy->copyID])
            ->assertCreated()
            ->assertJsonPath('penalty.loanID', null)
            ->assertJsonPath('penalty.student.studentID', $this->student->studentID);

        $this->assertSame('damaged', $copy->fresh()->status);
    }

    public function test_a_student_and_a_copy_they_hold_charges_them_and_closes_that_loan(): void
    {
        $copy = $this->copy('borrowed');
        $loan = $this->loan($copy, 'Active');

        $this->issue(['studentID' => $this->student->studentID, 'copyID' => $copy->copyID])
            ->assertCreated()
            ->assertJsonPath('penalty.loanID', $loan->loanID);

        $this->assertSame('Returned', $loan->fresh()->status);
    }

    public function test_a_copy_someone_else_holds_cannot_be_charged_to_this_student(): void
    {
        $copy = $this->copy('borrowed');
        $this->loan($copy, 'Active', $this->other);

        $this->issue(['studentID' => $this->student->studentID, 'copyID' => $copy->copyID])
            ->assertUnprocessable()->assertJsonValidationErrors('copy');

        $this->assertSame('borrowed', $copy->fresh()->status);
        $this->assertSame(0, Penalty::count());
    }

    public function test_a_penalty_needs_a_target_a_real_type_a_positive_amount_and_a_live_copy(): void
    {
        $copy = $this->copy();
        $target = ['studentID' => $this->student->studentID, 'copyID' => $copy->copyID];

        $this->issue([])->assertUnprocessable()->assertJsonValidationErrors(['studentID', 'copyID']);
        $this->issue([...$target, 'type' => 'late'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->issue([...$target, 'amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $retired = $this->copy('retired');
        $this->issue(['studentID' => $this->student->studentID, 'copyID' => $retired->copyID])->assertUnprocessable()->assertJsonValidationErrors('copy');
        $this->assertSame(0, Penalty::count());
    }

    public function test_a_copy_is_charged_only_once(): void
    {
        $copy = $this->copy();
        $target = ['studentID' => $this->student->studentID, 'copyID' => $copy->copyID];

        $this->issue($target)->assertCreated();
        $this->issue($target)->assertUnprocessable()->assertJsonValidationErrors('copy');
        $this->assertSame(1, Penalty::count());
    }

    public function test_a_loan_waiting_on_a_self_return_report_cannot_be_charged_yet(): void
    {
        $copy = $this->copy('borrowed');
        $loan = $this->loan($copy, 'Active');
        SelfReturnReport::create(['uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'reportedAt' => now(), 'verificationStatus' => 'Pending']);

        $this->issue(['loan' => $loan->uuid])->assertUnprocessable()->assertJsonValidationErrors('loan');

        $this->assertSame('Active', $loan->fresh()->status);
        $this->assertSame('borrowed', $copy->fresh()->status);
    }

    // ---- The two tabs ----

    public function test_the_lost_tab_lists_only_copy_penalties_and_the_penalties_tab_only_late_fees(): void
    {
        $copyPenalty = $this->open('lost');
        $loan = $this->loan($this->copy('borrowed'), 'Returned');
        $late = Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'studentID' => $loan->studentID, 'copyID' => $loan->copyID, 'amount' => 50,
            'computedAt' => now(), 'paymentStatus' => 'Unpaid',
            'penaltyTypeID' => PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid()])->penaltyTypeID,
        ]);

        $copyRows = $this->getJson('/api/librarian/penalties?kind=copy')->assertOk()->assertJsonPath('total', 1)->json('data');
        $this->assertSame($copyPenalty->uuid, $copyRows[0]['uuid']);
        // A penalty with no loan still shows who and which book.
        $this->assertNull($copyRows[0]['loan']);
        $this->assertSame($this->student->studentID, $copyRows[0]['student']['studentID']);
        $this->assertSame('Noli Me Tangere', $copyRows[0]['copy']['book']['title']);

        $lateRows = $this->getJson('/api/librarian/penalties?kind=late')->assertOk()->assertJsonPath('total', 1)->json('data');
        $this->assertSame((string) $late->uuid, $lateRows[0]['uuid']);

        $this->getJson('/api/librarian/penalties')->assertJsonPath('total', 2);
        $this->getJson('/api/librarian/penalties/stats?kind=copy')->assertJsonPath('unpaidCount', 1);
        $this->getJson('/api/librarian/penalties/stats?kind=late')->assertJsonPath('unpaidCount', 1);
    }

    public function test_the_lost_tab_search_finds_a_penalty_by_student_or_book(): void
    {
        $this->open('lost');

        $name = $this->student->user->firstName;
        $this->getJson('/api/librarian/penalties?kind=copy&search=' . urlencode($name))->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/penalties?kind=copy&search=Noli')->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/penalties?kind=copy&search=zzzz')->assertJsonPath('total', 0);
    }

    public function test_a_penalty_stays_flagged_long_unpaid_from_the_day_it_was_issued(): void
    {
        $penalty = $this->open('lost');

        $this->getJson('/api/librarian/penalties/stats?kind=copy')->assertJsonPath('longUnpaidCount', 0);

        $penalty->update(['computedAt' => now()->subDays(Penalty::LONG_UNPAID_DAYS + 1)]);

        $this->getJson('/api/librarian/penalties/stats?kind=copy')->assertJsonPath('longUnpaidCount', 1);
        $this->getJson('/api/librarian/penalties?kind=copy&status=longUnpaid')->assertJsonPath('total', 1);
    }

    // ---- Settling ----

    public function test_paying_records_the_receipt_and_removes_the_copy_from_the_catalog(): void
    {
        $penalty = $this->open('lost');
        $copy = $penalty->copy;

        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/settle", [])->assertUnprocessable();
        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/settle", ['receiptNumber' => 'OR-2026-00123'])->assertOk();

        $this->assertSame('Paid', $penalty->fresh()->paymentStatus);
        $this->assertSame('OR-2026-00123', $penalty->fresh()->receiptNumber);
        $this->assertSame('retired', $copy->fresh()->status);
        $this->assertDatabaseHas('book_stock_logs', ['copyID' => $copy->copyID, 'action' => 'removed', 'reason' => 'Lost']);
    }

    public function test_a_replacement_closes_the_penalty_adds_a_new_copy_and_removes_the_old_one(): void
    {
        $penalty = $this->open('damaged');
        $old = $penalty->copy;

        $response = $this->postJson("/api/librarian/penalties/{$penalty->uuid}/replace")
            ->assertOk()
            ->assertJsonPath('penalty.paymentStatus', 'Replaced');

        $new = BookCopy::where('uuid', $response->json('replacement.uuid'))->firstOrFail();

        $this->assertSame('available', $new->status);
        $this->assertSame($this->book->bookID, $new->bookID);
        $this->assertGreaterThan($old->accessionNumber, $new->accessionNumber);
        $this->assertSame('retired', $old->fresh()->status);
        $this->assertNotNull($penalty->fresh()->settledAt);
        $this->assertNull($penalty->fresh()->receiptNumber);
        $this->assertDatabaseHas('book_stock_logs', ['copyID' => $old->copyID, 'action' => 'removed', 'reason' => 'Damaged']);
        $this->assertDatabaseHas('book_stock_logs', ['copyID' => $new->copyID, 'action' => 'added']);

        // Replacing is not a payment: it can't be done twice, and the student owes nothing.
        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/replace")->assertUnprocessable();
        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/settle", ['receiptNumber' => 'X'])->assertUnprocessable();
        $this->assertSame(0.0, app(PenaltyRepositoryInterface::class)->unpaidTotalForStudent($this->student->studentID));
    }

    public function test_a_late_fee_cannot_be_replaced_or_cancelled(): void
    {
        $loan = $this->loan($this->copy('available'));
        $fine = Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'studentID' => $loan->studentID, 'copyID' => $loan->copyID, 'amount' => 50,
            'computedAt' => now(), 'paymentStatus' => 'Unpaid',
            'penaltyTypeID' => PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid()])->penaltyTypeID,
        ]);

        $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$fine->uuid}/replace")->assertUnprocessable();
        $this->postJson("/api/librarian/penalties/{$fine->uuid}/cancel")->assertUnprocessable();
    }

    // ---- Found or repaired ----

    public function test_cancelling_puts_the_copy_back_on_the_shelf_and_removes_the_penalty(): void
    {
        $penalty = $this->open('lost');
        $copy = $penalty->copy;

        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/cancel")->assertOk();

        $this->assertSame('available', $copy->fresh()->status);
        $this->assertSame(0, Penalty::count());
        $this->assertDatabaseHas('notifications', ['userID' => $this->student->studentID, 'type' => 'copy_penalty_cancelled']);
    }

    public function test_a_paid_penalty_cannot_be_cancelled(): void
    {
        $penalty = $this->open('lost');
        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/settle", ['receiptNumber' => 'OR-1'])->assertOk();

        $this->postJson("/api/librarian/penalties/{$penalty->uuid}/cancel")->assertUnprocessable();
    }

    public function test_marking_the_copy_found_in_the_library_cancels_its_unpaid_penalty(): void
    {
        $penalty = $this->open('lost');
        $copy = $penalty->copy;

        $this->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'available'])->assertOk();

        $this->assertSame('available', $copy->fresh()->status);
        $this->assertSame(0, Penalty::count());
    }

    public function test_a_copy_with_an_unpaid_penalty_cannot_be_removed_or_changed_otherwise(): void
    {
        $copy = $this->open('lost')->copy;

        $this->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'damaged'])->assertUnprocessable();
        $this->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'retired'])->assertUnprocessable();
        $this->postJson("/api/librarian/copies/{$copy->uuid}/remove", ['reason' => 'Lost'])->assertUnprocessable();
        $this->assertSame('lost', $copy->fresh()->status);
    }

    // ---- Late fees and the student's view ----

    public function test_a_late_fee_records_its_student_and_copy_and_ignores_a_lost_book_penalty(): void
    {
        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid()]);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);

        $copy = $this->copy('borrowed');
        $loan = $this->loan($copy, 'Active');

        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")->assertOk();

        $fine = Penalty::where('loanID', $loan->loanID)->firstOrFail();
        $this->assertSame($this->student->studentID, $fine->studentID);
        $this->assertSame($copy->copyID, $fine->copyID);

        // A lost-book penalty on the same loan is a different charge: the late fee's lookup does not pick it up.
        $this->issue(['studentID' => $this->student->studentID, 'copyID' => $copy->copyID])->assertCreated();
        $this->assertSame($fine->penaltyID, app(PenaltyRepositoryInterface::class)->firstOrNewForLoan($loan->loanID)->penaltyID);
    }

    public function test_the_student_sees_what_kind_of_penalty_it_is_even_with_no_loan(): void
    {
        $this->open('lost');

        $row = $this->actingAs($this->student->user)->getJson('/api/student/penalties')->assertOk()->json('penalties.0');

        $this->assertSame('lost', $row['kind']);
        $this->assertEquals(450, $row['amount']);
        $this->assertSame(0, $row['unitsLate']);
        $this->assertNull($row['rate']);
        $this->assertSame('Noli Me Tangere', $row['book']['title']);
    }
}
