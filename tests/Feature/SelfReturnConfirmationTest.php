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
use App\Models\SelfReturnReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A student's self-return report gets the same treatment as returning at the desk: a preview with the loan, the
 * book, the student and the late fee, and a verify that can settle the fee with it.
 */
class SelfReturnConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
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

        $this->student = Student::factory()->create(['registrationStatus' => 'approved', 'studentIDNumber' => '02-2024-009']);
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'circulation']);
        $book->authors()->attach(Author::factory()->create(['name' => 'Jose Rizal'])->authorID, ['uuid' => Str::uuid(), 'role' => null]);
        $this->copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'borrowed']);
    }

    private function report(\DateTimeInterface $due): SelfReturnReport
    {
        $loan = Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'copyID' => $this->copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDays(10), 'dueDate' => $due, 'status' => 'Active',
        ]);

        return SelfReturnReport::create(['uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'reportedAt' => now()->subHour(), 'verificationStatus' => 'Pending']);
    }

    private function act(string $method, SelfReturnReport $report, string $path, array $body = [])
    {
        return $this->actingAs($this->librarian)->{$method . 'Json'}("/api/librarian/self-return-reports/{$report->uuid}/{$path}", $body);
    }

    public function test_the_preview_shows_the_report_the_loan_the_book_the_student_and_the_fee(): void
    {
        $report = $this->report(now()->subDays(2)->subHours(12));

        $this->act('get', $report, 'preview')
            ->assertOk()
            ->assertJsonPath('preview.report.reportID', $report->reportID)
            ->assertJsonPath('preview.loan.overdue', true)
            ->assertJsonPath('preview.book.title', 'Noli Me Tangere')
            ->assertJsonPath('preview.book.authors.0', 'Jose Rizal')
            ->assertJsonPath('preview.student.studentIDNumber', '02-2024-009')
            ->assertJsonPath('preview.fine.unitsLate', 3)
            ->assertJsonPath('preview.fine.amount', 15)
            ->assertJsonPath('preview.fine.onRecord', null);
    }

    public function test_a_report_already_dealt_with_has_no_preview(): void
    {
        $report = $this->report(now()->addDay());
        $report->update(['verificationStatus' => 'Verified']);

        $this->act('get', $report, 'preview')->assertUnprocessable();
    }

    public function test_verifying_with_the_fee_paid_returns_the_book_and_settles_the_fine(): void
    {
        $report = $this->report(now()->subDays(2)->subHours(12));

        $this->act('post', $report, 'verify', ['finePaid' => true, 'receiptNumber' => 'OR-2026-00456'])
            ->assertOk()
            ->assertJsonPath('penalty.paymentStatus', 'Paid');

        $this->assertSame('Verified', $report->fresh()->verificationStatus);
        $this->assertSame('Returned', $report->loan->fresh()->status);
        $this->assertSame('available', $this->copy->fresh()->status);
        $penalty = Penalty::where('loanID', $report->loanID)->firstOrFail();
        $this->assertSame('Paid', $penalty->paymentStatus);
        $this->assertSame('OR-2026-00456', $penalty->receiptNumber);
    }

    public function test_collecting_the_fee_when_verifying_needs_the_cashiers_receipt_number(): void
    {
        $report = $this->report(now()->subDays(2)->subHours(12));

        $this->act('post', $report, 'verify', ['finePaid' => true])->assertUnprocessable()->assertJsonValidationErrors('receiptNumber');
        $this->assertSame('Pending', $report->fresh()->verificationStatus);
    }

    public function test_verifying_without_the_fee_leaves_it_unpaid(): void
    {
        $report = $this->report(now()->subDays(2)->subHours(12));

        $this->act('post', $report, 'verify', ['finePaid' => false])->assertOk()->assertJsonPath('penalty.paymentStatus', 'Unpaid');
        $this->act('post', $this->report(now()->subDay()->subHour()), 'verify')->assertOk();

        $this->assertSame('Unpaid', Penalty::where('loanID', $report->loanID)->firstOrFail()->paymentStatus);
    }

    public function test_verifying_a_book_whose_fine_was_already_paid_keeps_it_paid(): void
    {
        $report = $this->report(now()->subDays(2)->subHours(12));
        $type = PenaltyType::where('category', 'circulation')->firstOrFail();
        Penalty::create(['uuid' => Str::uuid(), 'loanID' => $report->loanID, 'penaltyTypeID' => $type->penaltyTypeID, 'amount' => 15, 'computedAt' => now(), 'paymentStatus' => 'Paid', 'settledAt' => now()]);

        $this->act('get', $report, 'preview')->assertJsonPath('preview.fine.onRecord.paymentStatus', 'Paid');
        $this->act('post', $report, 'verify')->assertOk()->assertJsonPath('penalty.paymentStatus', 'Paid');
        $this->assertSame(1, Penalty::where('loanID', $report->loanID)->count());
    }

    public function test_rejecting_leaves_the_book_on_loan_and_charges_nothing(): void
    {
        $report = $this->report(now()->subDays(3));

        $this->act('post', $report, 'reject', ['reason' => 'Book not at the shelf'])->assertOk();

        $this->assertSame('Rejected', $report->fresh()->verificationStatus);
        $this->assertSame('Book not at the shelf', $report->fresh()->rejectionReason);
        $this->assertSame('Active', $report->loan->fresh()->status);
        $this->assertSame(0, Penalty::where('loanID', $report->loanID)->count());
    }

    public function test_rejecting_needs_a_reason_the_student_will_see(): void
    {
        $report = $this->report(now()->subDays(3));

        $this->act('post', $report, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('Pending', $report->fresh()->verificationStatus);
    }

    public function test_finePaid_must_be_a_yes_or_no(): void
    {
        $report = $this->report(now()->addDay());

        $this->act('post', $report, 'verify', ['finePaid' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('finePaid');
        $this->assertSame('Pending', $report->fresh()->verificationStatus);
    }

    public function test_a_student_cannot_preview_or_verify_a_report(): void
    {
        $report = $this->report(now()->addDay());

        $this->actingAs($this->student->user)->getJson("/api/librarian/self-return-reports/{$report->uuid}/preview")->assertForbidden();
        $this->actingAs($this->student->user)->postJson("/api/librarian/self-return-reports/{$report->uuid}/verify")->assertForbidden();
    }
}
