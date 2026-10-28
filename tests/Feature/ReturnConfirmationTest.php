<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Returning an active loan asks for confirmation first: the "return preview" gives the desk everything about the
 * loan, the book and the student, and prices the late fee; the return itself can settle that fee at the same time.
 */
class ReturnConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private Book $book;
    private BookCopy $copy;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid(), 'name' => 'Overdue (circulation)']);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);

        $this->student = Student::factory()->create(['registrationStatus' => 'approved', 'studentIDNumber' => '02-2024-001', 'academicProgram' => 'BSIT']);
        $this->book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'circulation']);
        $this->book->authors()->attach(Author::factory()->create(['name' => 'Jose Rizal'])->authorID, ['uuid' => Str::uuid(), 'role' => null]);
        $this->copy = BookCopy::factory()->create(['bookID' => $this->book->bookID, 'status' => 'borrowed']);
    }

    private function loan(\DateTimeInterface $due, string $status = 'Active', ?BookCopy $copy = null): Loan
    {
        return Loan::create([
            'uuid'         => Str::uuid(),
            'studentID'    => $this->student->studentID,
            'copyID'       => ($copy ?? $this->copy)->copyID,
            'loanType'     => 'circulation',
            'checkoutDate' => now()->subDays(10),
            'dueDate'      => $due,
            'status'       => $status,
        ]);
    }

    private function preview(Loan $loan)
    {
        return $this->actingAs($this->librarian)->getJson("/api/librarian/loans/{$loan->uuid}/return-preview");
    }

    public function test_the_preview_shows_the_loan_the_book_and_the_student(): void
    {
        $loan = $this->loan(now()->addDays(2));

        $this->preview($loan)
            ->assertOk()
            ->assertJsonPath('preview.loan.loanID', $loan->loanID)
            ->assertJsonPath('preview.loan.overdue', false)
            ->assertJsonPath('preview.book.title', 'Noli Me Tangere')
            ->assertJsonPath('preview.book.authors.0', 'Jose Rizal')
            ->assertJsonPath('preview.book.accessionNumber', $this->copy->accessionNumber)
            ->assertJsonPath('preview.student.name', $this->student->user->fullName)
            ->assertJsonPath('preview.student.studentIDNumber', '02-2024-001')
            ->assertJsonPath('preview.student.academicProgram', 'BSIT')
            ->assertJsonPath('preview.fine.amount', 0)
            ->assertJsonPath('preview.fine.unitsLate', 0);
    }

    public function test_the_preview_prices_the_fine_of_an_overdue_loan(): void
    {
        // Two and a half days late: any started day counts, so 3 days at 5 a day.
        $loan = $this->loan(now()->subDays(2)->subHours(12));

        $this->preview($loan)
            ->assertOk()
            ->assertJsonPath('preview.loan.overdue', true)
            ->assertJsonPath('preview.fine.hasRule', true)
            ->assertJsonPath('preview.fine.unitsLate', 3)
            ->assertJsonPath('preview.fine.rateUnit', 'day')
            ->assertJsonPath('preview.fine.amount', 15);
    }

    public function test_a_collection_without_a_fine_rule_is_not_charged(): void
    {
        PenaltyRule::query()->delete();

        $this->preview($this->loan(now()->subDays(4)))
            ->assertOk()
            ->assertJsonPath('preview.loan.overdue', true)
            ->assertJsonPath('preview.fine.hasRule', false)
            ->assertJsonPath('preview.fine.amount', 0);
    }

    public function test_the_preview_includes_what_the_student_already_owes(): void
    {
        $earlier = $this->loan(now()->subDays(20), 'Returned', BookCopy::factory()->create(['bookID' => $this->book->bookID]));
        $type = PenaltyType::where('category', 'circulation')->firstOrFail();
        Penalty::create(['uuid' => Str::uuid(), 'loanID' => $earlier->loanID, 'penaltyTypeID' => $type->penaltyTypeID, 'amount' => 40, 'computedAt' => now(), 'paymentStatus' => 'Unpaid']);

        $this->preview($this->loan(now()->addDay()))->assertOk()->assertJsonPath('preview.student.unpaidFinesTotal', 40);
    }

    private function fineOnRecord(Loan $loan, string $status, float $amount = 15): Penalty
    {
        $type = PenaltyType::where('category', 'circulation')->firstOrFail();

        return Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'penaltyTypeID' => $type->penaltyTypeID, 'amount' => $amount,
            'computedAt' => now(), 'paymentStatus' => $status, 'settledAt' => $status === 'Paid' ? now() : null,
        ]);
    }

    public function test_the_preview_reports_no_fine_on_record_when_none_has_been_written(): void
    {
        $this->preview($this->loan(now()->subDays(2)))->assertOk()->assertJsonPath('preview.fine.onRecord', null);
    }

    public function test_the_preview_says_a_fine_on_record_is_already_paid(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));
        $this->fineOnRecord($loan, 'Paid');

        $this->preview($loan)->assertOk()
            ->assertJsonPath('preview.fine.onRecord.paymentStatus', 'Paid')
            ->assertJsonPath('preview.fine.onRecord.amount', 15)
            ->assertJsonPath('preview.fine.amount', 15);
    }

    public function test_the_preview_says_a_fine_on_record_is_unpaid(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));
        $this->fineOnRecord($loan, 'Unpaid');

        $this->preview($loan)->assertOk()->assertJsonPath('preview.fine.onRecord.paymentStatus', 'Unpaid');
    }

    public function test_the_other_fines_total_leaves_out_this_loans_own_unpaid_fine(): void
    {
        $earlier = $this->loan(now()->subDays(20), 'Returned', BookCopy::factory()->create(['bookID' => $this->book->bookID]));
        $this->fineOnRecord($earlier, 'Unpaid', 40);

        $loan = $this->loan(now()->subDays(2)->subHours(12));
        $this->fineOnRecord($loan, 'Unpaid', 15);

        // 40 from the earlier loan only; this loan's 15 is shown in the fee box, not counted twice.
        $this->preview($loan)->assertOk()->assertJsonPath('preview.student.unpaidFinesTotal', 40);
    }

    public function test_returning_a_book_whose_fine_was_already_paid_keeps_it_paid(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));
        $penalty = $this->fineOnRecord($loan, 'Paid');

        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")
            ->assertOk()
            ->assertJsonPath('penalty.paymentStatus', 'Paid');

        $this->assertSame('Paid', $penalty->fresh()->paymentStatus);
        $this->assertNotNull($penalty->fresh()->settledAt);
        $this->assertSame(1, Penalty::where('loanID', $loan->loanID)->count());
    }

    public function test_only_an_active_loan_can_be_previewed(): void
    {
        $this->preview($this->loan(now()->subDay(), 'Returned'))->assertUnprocessable();
    }

    public function test_returning_with_the_fee_paid_settles_the_fine(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => true, 'receiptNumber' => 'OR-2026-00123'])
            ->assertOk()
            ->assertJsonPath('penalty.paymentStatus', 'Paid')
            ->assertJsonPath('penalty.amount', '15.00');

        $this->assertSame('Returned', $loan->fresh()->status);
        $penalty = Penalty::where('loanID', $loan->loanID)->firstOrFail();
        $this->assertSame('Paid', $penalty->paymentStatus);
        $this->assertSame('OR-2026-00123', $penalty->receiptNumber);
        $this->assertNotNull($penalty->settledAt);
    }

    public function test_collecting_the_fee_with_the_return_needs_the_cashiers_receipt_number(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receiptNumber');

        $this->assertSame('Active', $loan->fresh()->status);
    }

    public function test_returning_without_the_fee_leaves_the_fine_unpaid(): void
    {
        $loan = $this->loan(now()->subDays(2)->subHours(12));

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => false])
            ->assertOk()
            ->assertJsonPath('penalty.paymentStatus', 'Unpaid');

        $this->assertSame('Returned', $loan->fresh()->status);
        $this->assertSame('Unpaid', Penalty::where('loanID', $loan->loanID)->firstOrFail()->paymentStatus);
    }

    public function test_a_return_that_sends_nothing_about_the_fee_leaves_it_unpaid(): void
    {
        $loan = $this->loan(now()->subDay()->subHour());

        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")->assertOk();

        $this->assertSame('Unpaid', Penalty::where('loanID', $loan->loanID)->firstOrFail()->paymentStatus);
    }

    public function test_paying_a_fee_that_does_not_exist_changes_nothing(): void
    {
        $loan = $this->loan(now()->addDay());

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => true, 'receiptNumber' => 'OR-1'])
            ->assertOk()
            ->assertJsonPath('penalty', null);

        $this->assertSame(0, Penalty::where('loanID', $loan->loanID)->count());
        $this->assertSame('Returned', $loan->fresh()->status);
    }

    public function test_finePaid_must_be_a_yes_or_no(): void
    {
        $loan = $this->loan(now()->addDay());

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('finePaid');

        $this->assertSame('Active', $loan->fresh()->status);
    }

    public function test_a_student_cannot_preview_or_return_a_loan_at_the_desk(): void
    {
        $loan = $this->loan(now()->addDay());

        $this->actingAs($this->student->user)->getJson("/api/librarian/loans/{$loan->uuid}/return-preview")->assertForbidden();
        $this->actingAs($this->student->user)->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => true])->assertForbidden();
    }
}
