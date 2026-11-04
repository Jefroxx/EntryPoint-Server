<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The "Remove this book" window shows everything tied to the book before it goes. */
class BookRemovalSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_the_summary_lists_what_is_tied_to_the_book(): void
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $shelf = BookCopy::factory()->create(['bookID' => $book->bookID]);
        $out = BookCopy::factory()->borrowed()->create(['bookID' => $book->bookID]);
        BookCopy::factory()->retired()->create(['bookID' => $book->bookID]);

        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        Loan::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $out->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now(), 'dueDate' => now()->addDays(3), 'status' => 'Active']);
        Loan::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $shelf->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subWeek(), 'dueDate' => now()->subDays(4), 'returnDate' => now()->subDays(5), 'status' => 'Returned']);
        Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID, 'status' => 'Waiting']);

        $summary = $this->actingAs($this->librarian)
            ->getJson("/api/librarian/books/{$book->uuid}/removal-summary")
            ->assertOk()
            ->json();

        $this->assertSame($book->title, $summary['book']['title']);
        $this->assertCount(2, $summary['copies']['items']);      // the retired one is not listed
        $this->assertSame(1, $summary['copies']['alreadyRemoved']);
        $this->assertSame(2, $summary['loans']['total']);
        $this->assertSame(1, $summary['loans']['returned']);
        $this->assertCount(1, $summary['loans']['active']);
        $this->assertSame(1, $summary['reservations']['total']);
        $this->assertCount(1, $summary['reservations']['open']);
        $this->assertCount(1, $summary['blockers']);
        $this->assertStringContainsString('still borrowed', $summary['blockers'][0]);
    }

    public function test_a_book_with_nothing_out_has_no_blockers(): void
    {
        $book = Book::factory()->create();
        BookCopy::factory()->create(['bookID' => $book->bookID]);

        $this->actingAs($this->librarian)
            ->getJson("/api/librarian/books/{$book->uuid}/removal-summary")
            ->assertOk()
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('loans.total', 0);
    }

    public function test_students_cannot_see_it(): void
    {
        $book = Book::factory()->create();
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)->getJson("/api/librarian/books/{$book->uuid}/removal-summary")->assertForbidden();
    }

    public function test_removing_a_book_declines_its_open_reservations_and_tells_the_students(): void
    {
        $book = Book::factory()->create(['title' => 'Noli Me Tangere']);
        BookCopy::factory()->create(['bookID' => $book->bookID]);

        $waiting = Student::factory()->create(['registrationStatus' => 'approved']);
        $ready = Student::factory()->create(['registrationStatus' => 'approved']);
        $done = Student::factory()->create(['registrationStatus' => 'approved']);

        $a = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $waiting->studentID, 'bookID' => $book->bookID, 'status' => 'Waiting']);
        $b = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $ready->studentID, 'bookID' => $book->bookID, 'status' => 'Accepted']);
        $c = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $done->studentID, 'bookID' => $book->bookID, 'status' => 'Fulfilled']);

        $this->actingAs($this->librarian)->deleteJson("/api/librarian/books/{$book->uuid}")->assertOk();

        $this->assertSame('Rejected', $a->fresh()->status);
        $this->assertSame('Rejected', $b->fresh()->status);
        $this->assertSame('Fulfilled', $c->fresh()->status);   // already collected: left alone

        foreach ([$waiting, $ready] as $student) {
            $this->assertDatabaseHas('notifications', [
                'userID' => $student->studentID,
                'type'   => 'reservation_rejected',
            ]);
        }
        $this->assertDatabaseMissing('notifications', ['userID' => $done->studentID, 'type' => 'reservation_rejected']);
    }
}
