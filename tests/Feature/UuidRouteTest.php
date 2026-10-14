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

/** Records are addressed by uuid in URLs; the integer ids stay inside the database. */
class UuidRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);

        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $this->student = Student::factory()->create(['registrationStatus' => 'approved']);
    }

    public function test_a_uuid_opens_the_record_and_the_integer_id_does_not(): void
    {
        $this->actingAs($this->librarian);

        $this->getJson("/api/librarian/books/{$this->book->uuid}")->assertOk()->assertJsonPath('book.title', $this->book->title);
        $this->getJson("/api/librarian/books/{$this->book->bookID}")->assertNotFound();
    }

    public function test_a_reservation_is_accepted_by_uuid_only(): void
    {
        $reservation = Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Waiting']);
        $this->actingAs($this->librarian);

        $this->postJson("/api/librarian/reservations/{$reservation->reservationID}/accept")->assertNotFound();
        $this->postJson("/api/librarian/reservations/{$reservation->uuid}/accept")->assertOk();
    }

    public function test_responses_the_client_builds_urls_from_carry_a_uuid(): void
    {
        $this->actingAs($this->librarian);
        $copy = BookCopy::where('bookID', $this->book->bookID)->first();

        $bookUuid = (string) $this->book->uuid;

        $this->assertSame((string) $copy->uuid, $this->getJson('/api/librarian/copies')->json('data.0.uuid'));
        $this->assertSame($bookUuid, $this->getJson('/api/librarian/copies')->json('data.0.book.uuid'));

        $this->actingAs($this->student->user);
        $this->assertSame($bookUuid, $this->getJson('/api/student/catalog')->json('data.0.uuid'));
        $this->assertSame($bookUuid, $this->getJson("/api/student/catalog/{$bookUuid}")->json('book.uuid'));
    }
}
