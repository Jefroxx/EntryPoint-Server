<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** One scanner: the code says whether it's a receipt, a reservation slip, a facility label or a student ID. */
class UniversalScanTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private Book $book;
    private BookCopy $copy;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);

        $this->student = Student::factory()->create(['registrationStatus' => 'approved', 'barcodeValue' => 'STI-TESTCODE01']);
        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $this->copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
    }

    private function scan(string $code, ?int $resID = null)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/scan', ['code' => $code, 'resID' => $resID]);
    }

    private function checkout(): array
    {
        $loanID = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $this->copy->copyID])
            ->json('loan.loanID');

        return [$loanID, 'L-' . str_pad((string) $loanID, 6, '0', STR_PAD_LEFT)];
    }

    public function test_a_student_id_is_attendance(): void
    {
        $this->scan('STI-TESTCODE01')->assertOk()->assertJsonPath('type', 'attendance')->assertJsonPath('action', 'check_in');
    }

    public function test_scanning_a_receipt_puts_the_book_in_the_librarians_hands_until_it_is_checked(): void
    {
        [$loanID, $code] = $this->checkout();

        $this->scan($code)->assertOk()
            ->assertJsonPath('type', 'loan_received')
            ->assertJsonPath('loan.loanID', $loanID)
            ->assertJsonPath('loan.bookTitle', $this->book->title);

        // Received, not returned: the copy is still off the shelf until the librarian has checked it.
        $this->assertSame('Received', \App\Models\Loan::find($loanID)->status);
        $this->assertSame('borrowed', $this->copy->fresh()->status);

        $this->scan($code)->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'This book was already received. Check it for damage to finish the return.');
    }

    public function test_finishing_a_received_book_puts_it_back_or_marks_it_damaged(): void
    {
        [$loanID, $code] = $this->checkout();
        $this->scan($code)->assertOk();

        $this->postJson("/api/librarian/loans/{$loanID}/finish-return", ['condition' => 'damaged', 'note' => 'Torn cover'])->assertOk();
        $this->assertSame('Returned', \App\Models\Loan::find($loanID)->status);
        $this->assertSame('damaged', $this->copy->fresh()->status);

        // And the good-condition path on a second loan of another copy.
        $other = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $second = $this->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $other->copyID])->json('loan.loanID');
        $this->scan('L-' . str_pad((string) $second, 6, '0', STR_PAD_LEFT))->assertOk();
        $this->postJson("/api/librarian/loans/{$second}/finish-return", ['condition' => 'good'])->assertOk();
        $this->assertSame('available', $other->fresh()->status);

        // An active loan can't be "finished" without being received first.
        $third = $this->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $other->copyID])->json('loan.loanID');
        $this->postJson("/api/librarian/loans/{$third}/finish-return", ['condition' => 'good'])->assertStatus(422);
    }

    public function test_a_facility_label_then_a_student_id_starts_a_session_and_scanning_it_again_ends_it(): void
    {
        $room = Resource::factory()->create(['name' => 'Study room 1', 'resourceType' => 'Study Room', 'status' => 'Available']);
        $label = 'F-' . str_pad((string) $room->resID, 6, '0', STR_PAD_LEFT);
        $this->assertSame($label, $room->barcodeValue);

        $this->scan($label)->assertOk()->assertJsonPath('type', 'facility')->assertJsonPath('resource.name', 'Study room 1');

        // The student ID that follows (with the facility armed) starts the session instead of attendance.
        $this->scan('STI-TESTCODE01', $room->resID)->assertOk()->assertJsonPath('type', 'facility_started');
        $this->assertSame('In Use', $room->fresh()->status);

        $this->scan($label)->assertOk()->assertJsonPath('type', 'facility_ended');
        $this->assertSame('Available', $room->fresh()->status);
    }

    public function test_a_reservation_slip_finds_the_reservation(): void
    {
        $reservation = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted']);

        $this->scan($reservation->pickupCode)->assertOk()
            ->assertJsonPath('type', 'reservation')
            ->assertJsonPath('reservation.reservationID', $reservation->reservationID);
    }

    public function test_unknown_codes_are_refused_with_a_reason(): void
    {
        $this->scan('L-999999')->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'No loan matches that receipt.');
        $this->scan('F-999999')->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'No facility matches that label.');
        $this->scan('STI-NOSUCHCODE')->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'Barcode not recognized.');
    }
}
