<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Once a reservation is accepted the student can't cancel it themselves: a librarian cancels it, releasing the held copy. */
class LibrarianCancelReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;
    private Student $ana;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);

        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $this->ana = Student::factory()->create(['registrationStatus' => 'approved']);
    }

    private function reserve(Student $student, string $status = 'Waiting'): Reservation
    {
        return Reservation::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $this->book->bookID,
            'status' => $status, 'reservedAt' => now(), 'acceptedAt' => $status === 'Accepted' ? now() : null,
        ]);
    }

    public function test_the_student_cannot_cancel_an_accepted_reservation(): void
    {
        $reservation = $this->reserve($this->ana, 'Accepted');

        $this->actingAs($this->ana->user)->deleteJson("/api/student/reservations/{$reservation->uuid}")->assertStatus(422);

        $this->assertSame('Accepted', $reservation->fresh()->status);
    }

    public function test_a_librarian_cancels_an_accepted_reservation_and_the_next_in_line_is_told(): void
    {
        $reservation = $this->reserve($this->ana, 'Accepted');
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->travel(1)->minutes();
        $this->reserve($ben);

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$reservation->uuid}/cancel")->assertOk();

        $this->assertSame('Rejected', $reservation->fresh()->status);
        $this->assertTrue(SystemNotification::where('userID', $this->ana->studentID)->where('type', 'reservation_cancelled')->exists());
        $this->assertTrue(SystemNotification::where('userID', $ben->studentID)->where('type', 'reservation_turn')->exists());
    }

    public function test_only_an_accepted_reservation_can_be_cancelled_by_the_librarian(): void
    {
        $reservation = $this->reserve($this->ana);

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$reservation->uuid}/cancel")->assertStatus(422);

        $this->assertSame('Waiting', $reservation->fresh()->status);
    }

    public function test_a_student_cannot_use_the_librarian_cancel(): void
    {
        $reservation = $this->reserve($this->ana, 'Accepted');

        $this->actingAs($this->ana->user)->postJson("/api/librarian/reservations/{$reservation->uuid}/cancel")->assertForbidden();
    }
}
