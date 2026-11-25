<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Reservation;
use App\Models\SelfReturnReport;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The desk's checkout rules, and how long an accepted reservation holds a copy. */
class CirculationRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $this->student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function copy(): BookCopy
    {
        return BookCopy::factory()->create(['bookID' => $this->book->bookID, 'status' => 'available']);
    }

    /** A copy of a book of its own: one student can only have one copy of a title at a time. */
    private function copyOfAnotherBook(): BookCopy
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);

        return BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'available']);
    }

    private function checkout(BookCopy $copy, ?Student $student = null)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/loans', ['studentID' => ($student ?? $this->student)->studentID, 'copyID' => $copy->copyID]);
    }

    private function loanFor(Student $student, string $due = '+2 days'): Loan
    {
        return Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $this->copyOfAnotherBook()->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDay(), 'dueDate' => now()->modify($due), 'status' => 'Active',
        ]);
    }

    // ---- who may borrow at the desk ----

    public function test_an_ordinary_checkout_still_works(): void
    {
        $this->checkout($this->copy())->assertCreated();
    }

    public function test_an_account_that_is_not_approved_cannot_borrow(): void
    {
        $pending = Student::factory()->create(['registrationStatus' => 'pending']);

        $this->checkout($this->copy(), $pending)->assertUnprocessable()->assertJsonValidationErrors('studentID');
        $this->assertSame(0, Loan::where('studentID', $pending->studentID)->count());
    }

    public function test_a_student_with_an_overdue_book_cannot_borrow_another(): void
    {
        $this->loanFor($this->student, '-3 days');

        $this->checkout($this->copy())->assertUnprocessable()->assertJsonPath('errors.studentID.0', 'They have 1 overdue book. Return it first.');
    }

    public function test_a_student_with_an_unpaid_penalty_cannot_borrow(): void
    {
        $loan = $this->loanFor($this->student, '+2 days');
        Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'studentID' => $this->student->studentID, 'copyID' => $loan->copyID,
            'penaltyTypeID' => PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()])->penaltyTypeID,
            'amount' => 25, 'computedAt' => now(), 'paymentStatus' => 'Unpaid',
        ]);

        $this->checkout($this->copy())->assertUnprocessable()->assertJsonPath('errors.studentID.0', 'They have an unpaid penalty. Settle it first (Circulation > Penalties).');
    }

    public function test_three_books_out_or_reserved_is_the_most_at_a_time(): void
    {
        $this->loanFor($this->student);
        $this->loanFor($this->student);
        $this->checkout($this->copy())->assertCreated();   // the third

        $this->checkout($this->copyOfAnotherBook())->assertUnprocessable()->assertJsonValidationErrors('studentID');
        $this->assertSame(3, Loan::where('studentID', $this->student->studentID)->count());
    }

    public function test_collecting_a_reservation_does_not_need_a_free_slot(): void
    {
        $this->loanFor($this->student);
        $this->loanFor($this->student);
        $copy = $this->copy();
        $reservation = Reservation::create([
            'uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID,
            'status' => 'Accepted', 'reservedAt' => now(), 'acceptedAt' => now(),
        ]);

        // Two loans plus the reservation is three slots: turning the reservation into a loan keeps it at three.
        $this->actingAs($this->librarian)->postJson('/api/librarian/loans', [
            'studentID' => $this->student->studentID, 'copyID' => $copy->copyID, 'reservationID' => $reservation->reservationID,
        ])->assertCreated();
    }

    public function test_a_student_cannot_have_two_copies_of_the_same_title(): void
    {
        $this->checkout($this->copy())->assertCreated();

        $this->checkout($this->copy())->assertUnprocessable()->assertJsonValidationErrors('studentID');
        $this->assertSame(1, Loan::where('studentID', $this->student->studentID)->count());
    }

    // ---- how long a reservation holds a copy ----

    public function test_a_reservation_is_held_for_a_day_unless_settings_say_otherwise(): void
    {
        $this->assertSame(24, AppSettings::holdHours());
        $this->assertSame(3, AppSettings::maxBooks());

        // Hours, so it can be shorter than a day; the limit on books is set in the same place.
        $this->actingAs($this->librarian)->putJson('/api/librarian/settings/borrowing', ['holdHours' => 6, 'maxBooks' => 5])->assertOk();
        $this->assertSame(6, AppSettings::holdHours());
        $this->assertSame(5, AppSettings::maxBooks());
        $overview = $this->getJson('/api/librarian/settings')->json();
        $this->assertSame(6, $overview['reservationHoldHours']);
        $this->assertSame(5, $overview['maxBooks']);

        $this->putJson('/api/librarian/settings/borrowing', ['holdHours' => 0, 'maxBooks' => 3])->assertUnprocessable();
        $this->putJson('/api/librarian/settings/borrowing', ['holdHours' => 721, 'maxBooks' => 3])->assertUnprocessable();
        $this->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 0])->assertUnprocessable();
        $this->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 21])->assertUnprocessable();
    }

    public function test_the_hold_is_described_in_days_or_hours(): void
    {
        $this->assertSame('1 day', AppSettings::describeHours(24));
        $this->assertSame('3 days', AppSettings::describeHours(72));
        $this->assertSame('36 hours', AppSettings::describeHours(36));
        $this->assertSame('1 hour', AppSettings::describeHours(1));
    }

    public function test_the_most_books_a_student_can_have_is_a_setting(): void
    {
        AppSettings::set(AppSettings::MAX_BOOKS, 1);
        $this->loanFor($this->student);

        // One book is the most now, so a second is refused, and the student app is told the limit.
        $this->checkout($this->copyOfAnotherBook())->assertUnprocessable()->assertJsonPath('errors.studentID.0', 'They already have 1 book out or reserved, which is the most at a time.');
        $stats = $this->actingAs($this->student->user)->getJson('/api/student/profile')->json('stats');
        $this->assertSame(1, $stats['maxBooks']);
        $this->assertSame(0, $stats['slotsLeft']);

        AppSettings::set(AppSettings::MAX_BOOKS, 5);
        $this->actingAs($this->librarian)->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $this->copy()->copyID])->assertCreated();
    }

    public function test_accepting_a_reservation_tells_the_student_how_long_it_is_held(): void
    {
        $this->copy();
        $reservation = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Waiting', 'reservedAt' => now()]);

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$reservation->uuid}/accept")->assertOk();

        $this->assertNotNull($reservation->fresh()->acceptedAt);
        $note = SystemNotification::where('userID', $this->student->studentID)->where('type', 'reservation_accepted')->firstOrFail();
        $this->assertStringContainsString('within 1 day', $note->message);
    }

    public function test_an_uncollected_reservation_expires_after_the_hold_and_frees_its_copy(): void
    {
        $this->copy();
        $late = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted', 'reservedAt' => now()->subDays(2), 'acceptedAt' => now()->subHours(30)]);
        $fresh = Reservation::create(['uuid' => Str::uuid(), 'studentID' => Student::factory()->create(['registrationStatus' => 'approved'])->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted', 'reservedAt' => now(), 'acceptedAt' => now()->subHours(2)]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame('Expired', $late->fresh()->status);
        $this->assertSame('Accepted', $fresh->fresh()->status);   // still inside its day
        $note = SystemNotification::where('userID', $this->student->studentID)->where('type', 'reservation_expired')->firstOrFail();
        $this->assertStringContainsString("wasn't collected within 1 day", $note->message);

        // The expired one can't be collected any more.
        $this->actingAs($this->librarian)->getJson('/api/librarian/reservations/lookup/'.$late->pickupCode)->assertUnprocessable();
    }

    // ---- the loan and penalty lists' filters ----

    public function test_searching_loans_still_respects_the_status_and_collection_filters(): void
    {
        $overdue = $this->loanFor($this->student, '-3 days');
        $fine = $this->loanFor($this->student, '+2 days');
        $overdue->copy->book->update(['title' => 'Atlas of Birds']);
        $fine->copy->book->update(['title' => 'Atlas of Fishes']);

        // Both books match the search; only the overdue one passes the status filter (it used to slip through).
        $rows = $this->actingAs($this->librarian)->getJson('/api/librarian/loans?search=Atlas&status=overdue')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($overdue->loanID, $rows[0]['loanID']);

        // The collection filter: none of these loans is in the Fiction collection.
        $this->getJson('/api/librarian/loans?area=fiction')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/librarian/loans?area=circulation')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_lost_and_damaged_penalties_can_be_filtered_by_what_happened(): void
    {
        foreach (['lost', 'damaged'] as $kind) {
            $copy = $this->copy();
            $this->actingAs($this->librarian)->postJson('/api/librarian/penalties', ['type' => $kind, 'amount' => 100, 'studentID' => Student::factory()->create(['registrationStatus' => 'approved'])->studentID, 'copyID' => $copy->copyID, 'reason' => 'test'])->assertCreated();
        }

        $this->getJson('/api/librarian/penalties?kind=copy')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/librarian/penalties?kind=copy&type=lost')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/librarian/penalties?kind=copy&type=damaged')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/librarian/penalties?kind=copy&area=fiction')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/librarian/penalties?kind=copy&area=circulation')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_loan_counts_do_not_overlap_and_each_has_its_own_filter(): void
    {
        $this->loanFor($this->student, '+5 days');    // due later
        $this->loanFor($this->student, '+5 hours');   // due within a day
        $this->loanFor($this->student, '-2 days');    // overdue

        $stats = $this->actingAs($this->librarian)->getJson('/api/librarian/loans/stats')->assertOk()->json();
        $this->assertSame(1, $stats['later']);
        $this->assertSame(1, $stats['dueSoon']);
        $this->assertSame(1, $stats['overdue']);

        $this->getJson('/api/librarian/loans?status=later')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/librarian/loans?status=dueSoon')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/librarian/loans?status=overdue')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_overdue_loan_carries_the_fine_it_has_run_up(): void
    {
        $type = PenaltyType::create(['uuid' => Str::uuid(), 'kind' => 'overdue', 'areaOfLibrary' => 'circulation']);
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 1]);
        $this->loanFor($this->student, '-4 days');
        $this->loanFor($this->student, '+2 days');

        $rows = $this->actingAs($this->librarian)->getJson('/api/librarian/loans')->assertOk()->json('data');
        $fines = collect($rows)->pluck('fineSoFar')->map(fn ($f) => $f === null ? null : (int) $f)->all();

        // 4 days late with a 1-day grace period is 3 full days, and the part of a day on top counts as another (so 15 or 20,
        // depending on the second the test runs); the loan that is not late has none.
        $overdueFine = max(array_filter($fines));
        $this->assertGreaterThanOrEqual(15, $overdueFine);
        $this->assertLessThanOrEqual(20, $overdueFine);
        $this->assertContains(null, $fines);
    }

    public function test_a_longer_hold_keeps_the_reservation_longer(): void
    {
        AppSettings::set(AppSettings::RESERVATION_HOLD_HOURS, 72);
        $this->copy();
        $held = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted', 'reservedAt' => now()->subDays(3), 'acceptedAt' => now()->subDays(2)]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame('Accepted', $held->fresh()->status);
    }

    // ---- handed-in books, editing and waiving fines, one copy per title ----

    private function lateFee(Loan $loan, float $amount = 50): Penalty
    {
        return Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'studentID' => $loan->studentID, 'copyID' => $loan->copyID, 'amount' => $amount,
            'computedAt' => now(), 'paymentStatus' => 'Unpaid',
            'penaltyTypeID' => PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()])->penaltyTypeID,
        ]);
    }

    public function test_the_loan_list_can_leave_out_books_that_are_handed_in(): void
    {
        $this->loanFor($this->student);
        $handedIn = $this->loanFor($this->student);
        $handedIn->update(['status' => 'Received']);

        $this->actingAs($this->librarian)->getJson('/api/librarian/loans')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/librarian/loans?hideReceived=1')->assertOk()->assertJsonCount(1, 'data');
        // Asking for them by name still works.
        $this->getJson('/api/librarian/loans?hideReceived=1&status=received')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_student_cannot_reserve_or_add_a_title_they_already_have_out(): void
    {
        $this->checkout($this->copy())->assertCreated();

        $this->actingAs($this->student->user)->postJson('/api/student/cart', ['bookID' => $this->book->bookID])
            ->assertUnprocessable()->assertJsonValidationErrors('bookID');
    }

    public function test_a_returned_fine_can_be_edited_and_the_student_is_told_the_new_amount(): void
    {
        $loan = $this->loanFor($this->student);
        $loan->update(['status' => 'Returned', 'returnDate' => now()]);
        $fine = $this->lateFee($loan);

        $this->actingAs($this->librarian)->patchJson("/api/librarian/penalties/{$fine->uuid}", ['amount' => 20, 'reason' => 'Agreed at the desk'])->assertOk();

        $this->assertSame('20.00', $fine->fresh()->amount);
        $this->assertSame('Agreed at the desk', $fine->fresh()->reason);
        $this->assertTrue(SystemNotification::where('userID', $this->student->studentID)->where('type', 'penalty_amount_changed')->exists());
    }

    public function test_a_fine_that_is_still_running_up_cannot_have_its_amount_typed_over(): void
    {
        $fine = $this->lateFee($this->loanFor($this->student, '-3 days'));

        $this->actingAs($this->librarian)->patchJson("/api/librarian/penalties/{$fine->uuid}", ['amount' => 5])->assertUnprocessable()->assertJsonValidationErrors('amount');
        // Its note can still change.
        $this->patchJson("/api/librarian/penalties/{$fine->uuid}", ['reason' => 'Student is away'])->assertOk();
    }

    public function test_a_late_fee_can_be_waived_with_a_reason_and_only_while_unpaid(): void
    {
        $loan = $this->loanFor($this->student);
        $loan->update(['status' => 'Returned', 'returnDate' => now()]);
        $fine = $this->lateFee($loan);

        $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$fine->uuid}/waive", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/librarian/penalties/{$fine->uuid}/waive", ['reason' => 'Library was closed'])->assertOk();

        $this->assertSame('Waived', $fine->fresh()->paymentStatus);
        $this->assertNotNull($fine->fresh()->settledAt);
        $this->assertTrue(SystemNotification::where('userID', $this->student->studentID)->where('type', 'penalty_waived')->exists());
        $this->postJson("/api/librarian/penalties/{$fine->uuid}/waive", ['reason' => 'Again'])->assertUnprocessable();
        $this->getJson('/api/librarian/penalties?status=Waived')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_very_long_book_title_does_not_break_the_penalty_notice(): void
    {
        $title = str_repeat('A very long book title ', 10);
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation', 'title' => $title]);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'available']);

        $this->actingAs($this->librarian)->postJson('/api/librarian/penalties', [
            'type' => 'damaged', 'studentID' => $this->student->studentID, 'copyID' => $copy->copyID, 'amount' => 12, 'reason' => 'Torn cover',
        ])->assertCreated();

        $this->assertTrue(SystemNotification::where('userID', $this->student->studentID)->where('message', 'like', '%A very long book title%')->exists());
    }

    // ---- self-return: late is judged when the student reported, not when the librarian checked ----

    private function reportedLoan(string $due, string $reported): array
    {
        $type = PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()]);
        PenaltyRule::firstOrCreate(['penaltyTypeID' => $type->penaltyTypeID], ['uuid' => Str::uuid(), 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);

        $loan = $this->loanFor($this->student, $due);
        $report = SelfReturnReport::create(['uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'reportedAt' => now()->modify($reported), 'verificationStatus' => 'Pending']);

        return [$loan, $report];
    }

    public function test_a_book_reported_before_it_was_due_is_never_fined_however_late_it_is_checked(): void
    {
        // Due 3 days ago, handed in 4 days ago, and only checked now.
        [$loan, $report] = $this->reportedLoan('-3 days', '-4 days');

        $this->actingAs($this->librarian)->postJson("/api/librarian/self-return-reports/{$report->uuid}/verify", ['finePaid' => false])->assertOk();

        $this->assertSame('Returned', $loan->fresh()->status);
        $this->assertTrue($loan->fresh()->returnDate->equalTo($report->reportedAt));
        $this->assertSame(0, Penalty::where('loanID', $loan->loanID)->count());
    }

    public function test_a_book_reported_late_is_fined_only_up_to_the_report(): void
    {
        // Due 4 days ago, handed in 2 days ago (2 days late), checked now: 2 days at 5, not 4.
        [$loan, $report] = $this->reportedLoan('-4 days', '-2 days');

        $this->actingAs($this->librarian)->postJson("/api/librarian/self-return-reports/{$report->uuid}/verify", ['finePaid' => false])->assertOk();

        $this->assertEquals(10, Penalty::where('loanID', $loan->loanID)->firstOrFail()->amount);
    }

    public function test_nothing_is_charged_while_a_self_return_report_waits(): void
    {
        [$loan] = $this->reportedLoan('-3 days', '-4 days');

        app(\App\Services\CirculationService::class)->accrueOverduePenalties();

        $this->assertSame(0, Penalty::where('loanID', $loan->loanID)->count());
        $this->assertNull($loan->fresh()->overdueNotifiedAt);
    }

    public function test_the_verify_window_shows_the_fee_as_of_the_report(): void
    {
        [$loan, $report] = $this->reportedLoan('-3 days', '-4 days');

        $preview = $this->actingAs($this->librarian)->getJson("/api/librarian/self-return-reports/{$report->uuid}/preview")->assertOk()->json('preview');

        $this->assertFalse($preview['loan']['overdue']);
        $this->assertSame(0, $preview['fine']['unitsLate']);
    }

    public function test_rejecting_an_on_time_report_starts_the_fine_from_the_rejection(): void
    {
        [$loan, $report] = $this->reportedLoan('-3 days', '-4 days');

        $this->actingAs($this->librarian)->postJson("/api/librarian/self-return-reports/{$report->uuid}/reject", ['reason' => 'Book not at the shelf'])->assertOk();

        $this->assertNotNull($loan->fresh()->fineStartsAt);
        // Three days overdue by the calendar, but the clock only started a moment ago.
        app(\App\Services\CirculationService::class)->accrueOverduePenalties();
        $this->assertSame(0, Penalty::where('loanID', $loan->loanID)->count());

        // A day after the rejection the fine is one day's worth.
        $loan->update(['fineStartsAt' => now()->subHours(23)]);
        app(\App\Services\CirculationService::class)->accrueOverduePenalties();
        $this->assertEquals(5, Penalty::where('loanID', $loan->loanID)->firstOrFail()->amount);
    }

    public function test_rejecting_a_report_made_when_it_was_already_overdue_changes_nothing(): void
    {
        [$loan, $report] = $this->reportedLoan('-3 days -12 hours', '-2 days');

        $this->actingAs($this->librarian)->postJson("/api/librarian/self-return-reports/{$report->uuid}/reject", ['reason' => 'Wrong book'])->assertOk();

        $this->assertNull($loan->fresh()->fineStartsAt);
        app(\App\Services\CirculationService::class)->accrueOverduePenalties();
        $this->assertEquals(20, Penalty::where('loanID', $loan->loanID)->firstOrFail()->amount);
    }

    public function test_a_late_report_that_is_rejected_pays_for_the_wait_but_one_that_is_verified_does_not(): void
    {
        // Due 3.5 days ago, handed in 2 days ago. Verified: only the 2 late days (10). Rejected: the whole time since the due date (20).
        [$verified, $verifiedReport] = $this->reportedLoan('-3 days -12 hours', '-2 days');
        [$rejected, $rejectedReport] = $this->reportedLoan('-3 days -12 hours', '-2 days');

        $this->actingAs($this->librarian)->postJson("/api/librarian/self-return-reports/{$verifiedReport->uuid}/verify", ['finePaid' => false])->assertOk();
        $this->postJson("/api/librarian/self-return-reports/{$rejectedReport->uuid}/reject", ['reason' => 'Wrong book'])->assertOk();
        app(\App\Services\CirculationService::class)->accrueOverduePenalties();

        $this->assertEquals(10, Penalty::where('loanID', $verified->loanID)->firstOrFail()->amount);
        $this->assertEquals(20, Penalty::where('loanID', $rejected->loanID)->firstOrFail()->amount);
    }

    public function test_returning_a_reported_book_at_the_desk_is_judged_as_of_the_report(): void
    {
        // Reported 4 days ago, due 3 days ago, and the librarian presses Return on the loan card today.
        [$loan, $report] = $this->reportedLoan('-3 days', '-4 days');

        $preview = $this->actingAs($this->librarian)->getJson("/api/librarian/loans/{$loan->uuid}/return-preview")->assertOk()->json();
        $this->assertSame(0, $preview['fine']['unitsLate'] ?? $preview['preview']['fine']['unitsLate'] ?? -1);

        $this->postJson("/api/librarian/loans/{$loan->uuid}/return", ['finePaid' => false])->assertOk();

        $this->assertSame('Returned', $loan->fresh()->status);
        $this->assertSame('Verified', $report->fresh()->verificationStatus);
        $this->assertSame(0, Penalty::where('loanID', $loan->loanID)->count());
    }

    // ---- audit fixes: a waived fine stays put, and a handed-in book still takes up a slot ----

    public function test_a_waived_fine_stops_growing_while_the_book_is_still_out(): void
    {
        $type = PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()]);
        PenaltyRule::firstOrCreate(['penaltyTypeID' => $type->penaltyTypeID], ['uuid' => Str::uuid(), 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);
        $loan = $this->loanFor($this->student, '-2 days');
        $service = app(\App\Services\CirculationService::class);

        $service->accrueOverduePenalties();
        $fine = Penalty::where('loanID', $loan->loanID)->firstOrFail();
        $this->assertEquals(15, $fine->amount);

        $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$fine->uuid}/waive", ['reason' => 'Library was closed'])->assertOk();

        // Days pass, and the book is still out.
        $loan->update(['dueDate' => now()->subDays(6)]);
        $service->accrueOverduePenalties();

        $this->assertEquals(15, $fine->fresh()->amount);
        $this->assertSame('Waived', $fine->fresh()->paymentStatus);
    }

    public function test_a_book_handed_in_but_not_yet_checked_still_counts_toward_the_borrowing_limit(): void
    {
        $this->actingAs($this->librarian)->putJson('/api/librarian/settings/borrowing', ['holdHours' => 6, 'maxBooks' => 1])->assertOk();
        $this->loanFor($this->student)->update(['status' => 'Received', 'returnDate' => now()]);

        $this->checkout($this->copyOfAnotherBook())->assertUnprocessable();
    }
}
