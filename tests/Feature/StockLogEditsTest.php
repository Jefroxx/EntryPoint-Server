<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookStockLog;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Any change to a copy or to a book's record shows in the stock log as "edited". */
class StockLogEditsTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_marking_a_copy_damaged_is_logged_as_an_edit(): void
    {
        $book = Book::factory()->create();
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'available']);

        $this->actingAs($this->librarian)->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'damaged'])->assertOk();

        $log = BookStockLog::where('copyID', $copy->copyID)->where('action', 'edited')->firstOrFail();
        $this->assertSame('Status changed', $log->reason);
        $this->assertSame('Status: On the shelf → Damaged', $log->note);
        $this->assertSame((string) $copy->accessionNumber, $log->accessionNumber);

        // Setting the same status again changes nothing, so nothing is logged.
        $this->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'damaged'])->assertOk();
        $this->assertSame(1, BookStockLog::where('copyID', $copy->copyID)->where('action', 'edited')->count());
    }

    public function test_editing_a_books_details_is_logged_with_what_changed(): void
    {
        $book = Book::factory()->create(['title' => 'Old Title', 'areaOfLibrary' => 'circulation', 'publisher' => 'Acme']);

        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['title' => 'New Title', 'areaOfLibrary' => 'fiction'])->assertOk();

        $log = BookStockLog::where('bookID', $book->bookID)->where('action', 'edited')->firstOrFail();
        $this->assertSame('Book details edited', $log->reason);
        $this->assertStringContainsString('Title: Old Title → New Title', $log->note);
        $this->assertStringContainsString('Area of the library: Circulation → Fiction', $log->note);
        $this->assertStringNotContainsString('Publisher', $log->note);   // untouched fields are not listed
        $this->assertNull($log->copyID);                                  // a book's record, not one copy's
        $this->assertNull($log->accessionNumber);
    }

    public function test_an_edit_that_changes_nothing_is_not_logged(): void
    {
        $book = Book::factory()->create(['title' => 'Same']);

        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['title' => 'Same'])->assertOk();

        $this->assertSame(0, BookStockLog::where('bookID', $book->bookID)->count());
    }

    public function test_a_new_dewey_number_is_logged(): void
    {
        $book = Book::factory()->create(['deweyNumber' => '005.1', 'deweyStatus' => 'ok']);

        $this->actingAs($this->librarian)->putJson("/api/librarian/books/{$book->uuid}/dewey", ['deweyNumber' => '006.3'])->assertOk();

        $log = BookStockLog::where('bookID', $book->bookID)->where('action', 'edited')->firstOrFail();
        $this->assertSame('Dewey number: 005.1 → 006.3', $log->note);
    }
}
