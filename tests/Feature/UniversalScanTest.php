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

    public function test_scanning_a_receipt_finds_the_loan_for_the_return_without_changing_it(): void
    {
        [$loanID, $code] = $this->checkout();

        $this->scan($code)->assertOk()
            ->assertJsonPath('type', 'loan_return')
            ->assertJsonPath('loan.loanID', $loanID)
            ->assertJsonPath('loan.status', 'Active')
            ->assertJsonPath('loan.bookTitle', $this->book->title);

        // The station only finds it: the return happens in the Return window, so the loan is untouched.
        $this->assertSame('Active', \App\Models\Loan::find($loanID)->status);
        $this->assertSame('borrowed', $this->copy->fresh()->status);
    }

    public function test_scanning_a_books_own_label_finds_its_loan(): void
    {
        [$loanID] = $this->checkout();

        $this->scan(strtolower($this->copy->barcodeValue))->assertOk()
            ->assertJsonPath('type', 'loan_return')
            ->assertJsonPath('loan.loanID', $loanID);
    }

    public function test_a_book_that_is_not_out_or_a_closed_receipt_says_so(): void
    {
        $this->scan($this->copy->barcodeValue)->assertStatus(422)
            ->assertJsonPath('errors.barcodeValue.0', "\"{$this->book->title}\" (accession no. {$this->copy->accessionNumber}) isn't checked out, so there is nothing to return.");
        $this->scan('BK-NOSUCHCODE')->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'No book matches that barcode.');

        [$loanID, $code] = $this->checkout();
        $loanUuid = \App\Models\Loan::find($loanID)->uuid;
        $this->postJson("/api/librarian/loans/{$loanUuid}/return")->assertOk();
        $this->scan($code)->assertStatus(422)->assertJsonPath('errors.barcodeValue.0', 'This loan is already closed: the book was returned.');
    }

    public function test_a_book_already_handed_in_is_found_for_its_check(): void
    {
        [$loanID, $code] = $this->checkout();
        $loanUuid = \App\Models\Loan::find($loanID)->uuid;
        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loanUuid}/receive")->assertOk();

        $this->scan($code)->assertOk()->assertJsonPath('type', 'loan_return')->assertJsonPath('loan.status', 'Received');
    }

    public function test_finishing_a_received_book_puts_it_back_or_marks_it_damaged(): void
    {
        [$loanID, $code] = $this->checkout();
        $this->postJson('/api/librarian/loans/' . \App\Models\Loan::find($loanID)->uuid . '/receive')->assertOk();

        $loanUuid = \App\Models\Loan::find($loanID)->uuid;
        $this->postJson("/api/librarian/loans/{$loanUuid}/finish-return", ['condition' => 'damaged', 'note' => 'Torn cover'])->assertOk();
        $this->assertSame('Returned', \App\Models\Loan::find($loanID)->status);
        $this->assertSame('damaged', $this->copy->fresh()->status);

        // And the good-condition path on a second loan of another copy.
        $other = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $second = $this->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $other->copyID])->json('loan.loanID');
        $secondUuid = \App\Models\Loan::find($second)->uuid;
        $this->postJson("/api/librarian/loans/{$secondUuid}/receive")->assertOk();
        $this->postJson("/api/librarian/loans/{$secondUuid}/finish-return", ['condition' => 'good'])->assertOk();
        $this->assertSame('available', $other->fresh()->status);

        // An active loan can't be "finished" without being received first.
        $third = $this->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $other->copyID])->json('loan.loanID');
        $thirdUuid = \App\Models\Loan::find($third)->uuid;
        $this->postJson("/api/librarian/loans/{$thirdUuid}/finish-return", ['condition' => 'good'])->assertStatus(422);
    }

    public function test_a_facility_label_then_a_student_id_starts_a_session_and_scanning_it_again_ends_it(): void
    {
        $room = Resource::factory()->create(['name' => 'Study room 1', 'resourceType' => 'Study Room', 'status' => 'Available']);
        $label = 'F-' . str_pad((string) $room->resID, 6, '0', STR_PAD_LEFT);
        $this->assertSame($label, $room->barcodeValue);

        $this->scan($label)->assertOk()->assertJsonPath('type', 'facility')->assertJsonPath('resource.name', 'Study room 1');

        // A session is for a student who is in the library: one who has not logged in is turned away.
        $this->scan('STI-TESTCODE01', $room->resID)->assertUnprocessable();
        $this->assertSame('Available', $room->fresh()->status);

        // Once they are logged in (a plain ID scan is attendance), the student ID that follows
        // (with the facility armed) starts the session instead of attendance.
        $this->scan('STI-TESTCODE01')->assertOk()->assertJsonPath('action', 'check_in');
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
        // The message says what was read, so a librarian can tell a wrong barcode from one that wasn't matched.
        $this->scan('STI-NOSUCHCODE')->assertStatus(422)
            ->assertJsonPath('errors.barcodeValue.0', fn (string $m) => str_starts_with($m, 'Barcode not recognized: read "STI-NOSUCHCODE"'));
    }

    public function test_a_slip_read_with_extra_characters_around_it_is_still_a_slip(): void
    {
        $reservation = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted']);

        // A symbology prefix some scanners add, a stray space, lower case: all still this slip.
        foreach ([']C0' . $reservation->pickupCode, ' ' . strtolower($reservation->pickupCode) . ' '] as $read) {
            $this->scan($read)->assertOk()
                ->assertJsonPath('type', 'reservation')
                ->assertJsonPath('reservation.reservationID', $reservation->reservationID);
        }
    }
}
