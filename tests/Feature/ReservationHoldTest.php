<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** An accepted reservation holds a copy: the book's available count drops on accept, not only at checkout. */
class ReservationHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);

        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        BookCopy::factory()->create(['bookID' => $this->book->bookID]);
    }

    private function reserve(Student $student): Reservation
    {
        return Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Waiting']);
    }

    private function availableCopiesSeenBy(Student $student): int
    {
        return $this->actingAs($student->user)->getJson("/api/student/catalog/{$this->book->uuid}")->json('book.availableCopies');
    }

    public function test_accepting_a_reservation_drops_the_available_count_and_blocks_a_second_accept(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $first = $this->reserve($ana);
        $second = $this->reserve($ben);

        $this->assertSame(1, $this->availableCopiesSeenBy($ben));

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$first->uuid}/accept")->assertOk();

        $this->assertSame(0, $this->availableCopiesSeenBy($ben));

        // The only copy is now held for Ana, so Ben's reservation can't be accepted.
        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$second->uuid}/accept")->assertStatus(422);
    }

    public function test_a_held_copy_cannot_be_lent_to_someone_without_the_reservation(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $reservation = $this->reserve($ana);
        $copy = BookCopy::where('bookID', $this->book->bookID)->first();

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$reservation->uuid}/accept")->assertOk();

        $this->postJson('/api/librarian/loans', ['studentID' => $ben->studentID, 'copyID' => $copy->copyID])
            ->assertStatus(422)
            ->assertJsonPath('errors.copyID.0', 'Every available copy of this book is held for an accepted reservation.');

        $this->postJson('/api/librarian/loans', [
            'studentID' => $ana->studentID, 'copyID' => $copy->copyID, 'reservationID' => $reservation->reservationID,
        ])->assertCreated();
    }

    public function test_with_three_free_copies_only_the_first_three_in_line_can_be_accepted(): void
    {
        BookCopy::factory()->count(2)->create(['bookID' => $this->book->bookID]); // 3 copies in all

        foreach (range(1, 4) as $minute) {
            $student = Student::factory()->create(['registrationStatus' => 'approved']);
            Reservation::create([
                'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $this->book->bookID,
                'status' => 'Waiting', 'reservedAt' => now()->addMinutes($minute),
            ]);
        }

        $blocks = collect($this->actingAs($this->librarian)->getJson('/api/librarian/reservations?status=Waiting')->json('reservations'))
            ->sortBy('reservedAt')->pluck('acceptBlock')->values();

        $this->assertNull($blocks[0]);
        $this->assertNull($blocks[1]);
        $this->assertNull($blocks[2]);
        $this->assertNotNull($blocks[3]);
    }

    public function test_checking_out_to_a_student_with_an_accepted_reservation_fulfils_it(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $reservation = $this->reserve($ana);
        $copy = BookCopy::where('bookID', $this->book->bookID)->first();

        $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$reservation->uuid}/accept")->assertOk();

        // Checked out without picking the reservation: it must still stop holding a copy.
        $this->postJson('/api/librarian/loans', ['studentID' => $ana->studentID, 'copyID' => $copy->copyID])->assertCreated();

        $this->assertSame('Fulfilled', $reservation->fresh()->status);
    }
}
