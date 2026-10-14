<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian', 'firstName' => 'Rosa', 'middleInitial' => null, 'lastName' => 'Dela Cruz']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid(), 'name' => 'Overdue (circulation)']);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);
    }

    public function test_checkout_returns_a_receipt_with_the_book_due_date_and_fine(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'studentIDNumber' => '02-2024-001']);
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'publisher' => 'Anvil', 'edition' => '2nd', 'areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $this->travelTo(now()->setTime(10, 0));

        $receipt = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $student->studentID, 'copyID' => $copy->copyID])
            ->assertCreated()
            ->json('receipt');

        $this->assertMatchesRegularExpression('/^L-\d{6}$/', $receipt['receiptNumber']);
        $this->assertSame('02-2024-001', $receipt['student']['studentIDNumber']);
        $this->assertSame('Noli Me Tangere', $receipt['book']['title']);
        $this->assertSame('Anvil', $receipt['book']['publisher']);
        $this->assertSame('2nd', $receipt['book']['edition']);
        $this->assertSame($copy->accessionNumber, $receipt['book']['accessionNumber']);
        $this->assertSame(now()->addDays(3)->toDateString(), substr($receipt['dueDate'], 0, 10));
        $this->assertEquals(['rate' => 5.0, 'rateUnit' => 'day'], $receipt['fine']);
        $this->assertSame('Rosa Dela Cruz', $receipt['printedBy']);
    }

    public function test_a_student_can_open_their_own_receipt_but_not_someone_elses(): void
    {
        $owner = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create(['title' => 'El Filibusterismo', 'areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $loanID = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $owner->studentID, 'copyID' => $copy->copyID])
            ->json('loan.loanID');
        $loanUuid = \App\Models\Loan::find($loanID)->uuid;

        $this->actingAs($owner->user)
            ->getJson("/api/student/loans/{$loanUuid}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt.book.title', 'El Filibusterismo')
            ->assertJsonPath('receipt.printedBy', null);

        $this->actingAs($other->user)->getJson("/api/student/loans/{$loanUuid}/receipt")->assertNotFound();
    }

    public function test_a_receipt_can_be_reprinted_and_students_cannot_fetch_it(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $loanID = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $student->studentID, 'copyID' => $copy->copyID])
            ->json('loan.loanID');
        $loanUuid = \App\Models\Loan::find($loanID)->uuid;

        $this->getJson("/api/librarian/loans/{$loanUuid}/receipt")->assertOk()->assertJsonPath('receipt.book.title', $book->title);

        $this->actingAs($student->user)->getJson("/api/librarian/loans/{$loanUuid}/receipt")->assertForbidden();
    }

    public function test_an_accepted_reservation_has_a_pickup_code_the_desk_can_look_up_and_check_out(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);
        $reservation = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID, 'status' => 'Accepted']);

        $code = 'R-' . str_pad((string) $reservation->reservationID, 6, '0', STR_PAD_LEFT);

        $this->actingAs($student->user)->getJson('/api/student/reservations')
            ->assertOk()
            ->assertJsonPath('reservations.0.pickupCode', $code);

        $this->actingAs($this->librarian)->getJson("/api/librarian/reservations/lookup/{$code}")
            ->assertOk()
            ->assertJsonPath('reservation.reservationID', $reservation->reservationID)
            ->assertJsonPath('reservation.student.studentID', $student->studentID);

        $this->postJson('/api/librarian/loans', [
            'studentID' => $student->studentID, 'copyID' => $copy->copyID, 'reservationID' => $reservation->reservationID,
        ])->assertCreated();

        $this->getJson("/api/librarian/reservations/lookup/{$code}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This reservation has already been collected.');
    }

    public function test_lookup_refuses_unknown_and_unaccepted_codes(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create();
        $waiting = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID, 'status' => 'Waiting']);

        $this->actingAs($this->librarian);
        $this->getJson('/api/librarian/reservations/lookup/nonsense')->assertStatus(422);
        $this->getJson('/api/librarian/reservations/lookup/R-999999')->assertStatus(422);
        $this->getJson('/api/librarian/reservations/lookup/R-' . str_pad((string) $waiting->reservationID, 6, '0', STR_PAD_LEFT))
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This reservation has not been accepted yet.');
    }
}
