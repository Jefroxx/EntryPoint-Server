<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookStockLog;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The librarian's stock log: every copy added to or removed from the catalog is recorded. */
class StockLogTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian', 'firstName' => 'Rosa', 'middleInitial' => null, 'lastName' => 'Dela Cruz']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $this->book = Book::factory()->create(['title' => 'Noli Me Tangere']);
        BookCopy::factory()->create(['bookID' => $this->book->bookID]);
    }

    public function test_adding_copies_to_an_existing_book_logs_each_one(): void
    {
        $response = $this->actingAs($this->librarian)
            ->postJson("/api/librarian/books/{$this->book->uuid}/copies", ['quantity' => 2, 'note' => 'Donated by the Class of 2025'])
            ->assertCreated();

        $this->assertCount(2, $response->json('copies'));
        $this->assertSame(3, BookCopy::where('bookID', $this->book->bookID)->count());

        $logs = BookStockLog::where('action', 'added')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Noli Me Tangere', $logs[0]->bookTitle);
        $this->assertSame('Donated by the Class of 2025', $logs[0]->note);
        $this->assertSame('Rosa Dela Cruz', $logs[0]->librarianName);
    }

    public function test_removing_a_copy_retires_it_and_logs_the_reason(): void
    {
        $extra = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->postJson("/api/librarian/copies/{$extra->uuid}/remove", ['reason' => 'Damaged', 'note' => 'Water damage'])
            ->assertOk();

        $this->assertSame('retired', $extra->fresh()->status);

        $log = BookStockLog::where('action', 'removed')->first();
        $this->assertSame('Damaged', $log->reason);
        $this->assertSame((string) $extra->accessionNumber, $log->accessionNumber);

        $this->postJson("/api/librarian/copies/{$extra->uuid}/remove", ['reason' => 'Lost'])->assertStatus(422);
        $this->postJson("/api/librarian/copies/{$extra->uuid}/remove", ['reason' => 'Because'])->assertStatus(422);
    }

    public function test_a_borrowed_copy_or_one_held_for_a_reservation_cannot_be_removed(): void
    {
        $borrowed = BookCopy::factory()->create(['bookID' => $this->book->bookID, 'status' => 'borrowed']);
        $this->actingAs($this->librarian)->postJson("/api/librarian/copies/{$borrowed->uuid}/remove", ['reason' => 'Lost'])->assertStatus(422);

        // The book's only shelf copy is promised to an accepted reservation.
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $this->book->bookID, 'status' => 'Accepted']);
        $held = BookCopy::where('bookID', $this->book->bookID)->where('status', 'available')->first();

        $this->postJson("/api/librarian/copies/{$held->uuid}/remove", ['reason' => 'Lost'])->assertStatus(422);
        $this->assertSame(0, BookStockLog::where('action', 'removed')->count());
    }

    public function test_the_stock_log_lists_newest_first_and_filters_by_action(): void
    {
        $this->actingAs($this->librarian);
        $this->postJson("/api/librarian/books/{$this->book->uuid}/copies", ['quantity' => 1])->assertCreated();
        $copyID = BookCopy::where('bookID', $this->book->bookID)->orderByDesc('copyID')->value('copyID');
        $copyUuid = BookCopy::find($copyID)->uuid;
        $this->postJson("/api/librarian/copies/{$copyUuid}/remove", ['reason' => 'Withdrawn'])->assertOk();

        $this->getJson('/api/librarian/stock-logs')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.action', 'removed');

        $this->getJson('/api/librarian/stock-logs?action=added')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/stock-logs?search=Noli')->assertOk()->assertJsonPath('total', 2);
    }
}
