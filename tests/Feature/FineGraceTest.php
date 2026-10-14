<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A fine rule's grace period is days after the due time with no charge. Lateness is counted once it has passed.
 */
class FineGraceTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen on a whole second (the database keeps whole seconds), so "four days late" is exactly four days.
        $this->travelTo(now()->startOfSecond());

        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $this->student = Student::factory()->create(['registrationStatus' => 'approved']);
    }

    private function rule(int $graceDays, string $unit = 'day'): void
    {
        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid(), 'name' => 'Overdue (circulation)']);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => $unit, 'gracePeriodDays' => $graceDays]);
    }

    private function overdueLoan(\DateTimeInterface $due): Loan
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'borrowed']);

        return Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'copyID' => $copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDays(20), 'dueDate' => $due, 'status' => 'Active',
        ]);
    }

    private function fine(Loan $loan): array
    {
        return $this->actingAs($this->librarian)->getJson("/api/librarian/loans/{$loan->uuid}/return-preview")->assertOk()->json('preview.fine');
    }

    public function test_nothing_is_charged_inside_the_grace_period(): void
    {
        $this->rule(graceDays: 2);
        $loan = $this->overdueLoan(now()->subDay());

        $fine = $this->fine($loan);
        $this->assertSame(0, $fine['unitsLate']);
        $this->assertEquals(0, $fine['amount']);
        $this->assertSame(2, $fine['graceDays']);

        // Returning it inside the grace period writes no fine at all.
        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")->assertOk();
        $this->assertSame(0, $loan->penalties()->count());
    }

    public function test_lateness_is_counted_after_the_grace_period(): void
    {
        $this->rule(graceDays: 2);

        // Four days late: two days of grace, then two days charged.
        $this->assertSame(2, $this->fine($this->overdueLoan(now()->subDays(4)))['unitsLate']);
        $this->assertEquals(10, $this->fine($this->overdueLoan(now()->subDays(4)))['amount']);

        // Two and a half days late: half a day past the grace period still counts as one unit.
        $this->assertSame(1, $this->fine($this->overdueLoan(now()->subDays(2)->subHours(12)))['unitsLate']);
    }

    public function test_with_no_grace_period_fines_start_at_the_due_time_as_before(): void
    {
        $this->rule(graceDays: 0);

        $fine = $this->fine($this->overdueLoan(now()->subDays(3)));
        $this->assertSame(3, $fine['unitsLate']);
        $this->assertEquals(15, $fine['amount']);
    }

    public function test_the_hourly_accrual_respects_the_grace_period(): void
    {
        $this->rule(graceDays: 2);
        $inside = $this->overdueLoan(now()->subDay());
        $past = $this->overdueLoan(now()->subDays(4));

        app(\App\Services\CirculationService::class)->accrueOverduePenalties();

        $this->assertSame(0, $inside->penalties()->count());
        $this->assertEquals(10, $past->penalties()->first()->amount);
    }

    public function test_signed_out_api_calls_get_a_401_even_without_a_json_header(): void
    {
        // No Accept header: this used to redirect to a "login" route that does not exist and fail with a 500.
        $this->get('/api/librarian/loans')->assertStatus(401);
        $this->getJson('/api/student/loans')->assertStatus(401);
    }
}
