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

class CopyStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->book = Book::factory()->create();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_a_copy_can_be_marked_damaged_then_back_on_the_shelf(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'damaged'])
            ->assertOk()
            ->assertJsonPath('copy.status', 'damaged');

        $this->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'available'])->assertOk();
        $this->assertSame('available', $copy->fresh()->status);
    }

    public function test_retiring_a_copy_takes_it_out_of_the_book_catalog(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'retired'])
            ->assertOk();

        $this->getJson('/api/librarian/copies')->assertJsonPath('total', 0);
    }

    public function test_a_copy_out_on_loan_cannot_be_edited(): void
    {
        $copy = BookCopy::factory()->borrowed()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'lost'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('borrowed', $copy->fresh()->status);
    }

    public function test_borrowed_cannot_be_set_by_hand(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'borrowed'])
            ->assertUnprocessable();
    }

    public function test_a_copys_cost_source_and_remarks_can_be_corrected(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['cost' => 250.5, 'sourceOfFund' => '  Donation ', 'remarks' => 'From the Class of 2025'])
            ->assertOk()
            ->assertJsonPath('copy.sourceOfFund', 'Donation');

        $fresh = $copy->fresh();
        $this->assertSame('Donation', $fresh->sourceOfFund);
        $this->assertEquals(250.5, $fresh->cost);
        $this->assertSame('From the Class of 2025', $fresh->remarks);
        $this->assertSame('available', $fresh->status);

        // A blank remark clears it.
        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['remarks' => ''])
            ->assertOk();
        $this->assertNull($copy->fresh()->remarks);
    }

    public function test_correcting_a_copys_record_is_written_to_the_stock_log(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID, 'cost' => 350, 'sourceOfFund' => 'Library budget']);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['cost' => 400, 'sourceOfFund' => 'Library budget'])
            ->assertOk();

        $log = BookStockLog::where('copyID', $copy->copyID)->where('action', 'edited')->sole();
        $this->assertSame('Cost: ₱350.00 → ₱400.00', $log->note);
        $this->assertSame((string) $copy->accessionNumber, $log->accessionNumber);
        $this->assertSame($this->book->title, $log->bookTitle);

        // Saving the same values again changes nothing, so nothing new is logged.
        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['cost' => 400])
            ->assertOk();
        $this->assertSame(1, BookStockLog::where('action', 'edited')->count());

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/stock-logs?action=edited')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_borrowed_copys_record_can_be_corrected_but_not_its_status(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID, 'status' => 'borrowed']);

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['cost' => 99])
            ->assertOk();

        $this->assertEquals(99, $copy->fresh()->cost);
        $this->assertSame('borrowed', $copy->fresh()->status);
    }

    public function test_an_edit_must_change_something_and_the_record_must_be_valid(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $url = "/api/librarian/copies/{$copy->uuid}";

        $this->actingAs($this->librarian)->patchJson($url, [])->assertUnprocessable();
        $this->actingAs($this->librarian)->patchJson($url, ['cost' => -1])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->actingAs($this->librarian)->patchJson($url, ['sourceOfFund' => ''])->assertUnprocessable()->assertJsonValidationErrors('sourceOfFund');
    }

    public function test_students_cannot_edit_copies(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'lost'])
            ->assertForbidden();
    }
}
