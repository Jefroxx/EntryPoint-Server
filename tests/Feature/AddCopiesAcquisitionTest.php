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

/**
 * Adding copies of a book already in the catalog records where they came from: source of fund and cost are
 * required, remarks are the only optional field.
 */
class AddCopiesAcquisitionTest extends TestCase
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

    private function add(array $payload)
    {
        return $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$this->book->uuid}/copies", $payload);
    }

    public function test_copies_are_added_with_their_source_of_fund_cost_and_remarks(): void
    {
        $this->add(['quantity' => 2, 'sourceOfFund' => 'Library budget', 'cost' => 350.5, 'remarks' => 'Purchase order #1042'])
            ->assertCreated()
            ->assertJsonCount(2, 'copies');

        $copies = BookCopy::where('bookID', $this->book->bookID)->get();
        $this->assertCount(2, $copies);
        foreach ($copies as $copy) {
            $this->assertSame('Library budget', $copy->sourceOfFund);
            $this->assertSame('350.50', $copy->cost);
            $this->assertSame('Purchase order #1042', $copy->remarks);
        }
    }

    public function test_remarks_are_optional(): void
    {
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Donation', 'cost' => 0])->assertCreated();

        $copy = BookCopy::where('bookID', $this->book->bookID)->firstOrFail();
        $this->assertSame('Donation', $copy->sourceOfFund);
        $this->assertSame('0.00', $copy->cost);
        $this->assertNull($copy->remarks);
    }

    public function test_blank_remarks_are_stored_as_nothing(): void
    {
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Gift', 'cost' => 10, 'remarks' => '   '])->assertCreated();

        $this->assertNull(BookCopy::where('bookID', $this->book->bookID)->firstOrFail()->remarks);
    }

    public function test_the_source_of_fund_is_required(): void
    {
        $this->add(['quantity' => 1, 'cost' => 100])->assertUnprocessable()->assertJsonValidationErrors('sourceOfFund');
        $this->add(['quantity' => 1, 'sourceOfFund' => '', 'cost' => 100])->assertUnprocessable()->assertJsonValidationErrors('sourceOfFund');

        $this->assertSame(0, BookCopy::where('bookID', $this->book->bookID)->count());
    }

    public function test_the_cost_is_required_and_cannot_be_negative_or_text(): void
    {
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Library budget'])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Library budget', 'cost' => -1])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Library budget', 'cost' => 'free'])->assertUnprocessable()->assertJsonValidationErrors('cost');

        $this->assertSame(0, BookCopy::where('bookID', $this->book->bookID)->count());
    }

    public function test_the_remarks_go_into_the_stock_log(): void
    {
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Alumni gift', 'cost' => 0, 'remarks' => 'Class of 2025'])->assertCreated();

        $this->assertDatabaseHas('book_stock_logs', ['bookID' => $this->book->bookID, 'action' => 'added', 'note' => 'Class of 2025']);
    }

    public function test_the_catalog_lists_each_copys_own_fund_cost_and_remarks(): void
    {
        $this->add(['quantity' => 1, 'sourceOfFund' => 'Library budget', 'cost' => 99, 'remarks' => 'Rush order'])->assertCreated();

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies')
            ->assertOk()
            ->assertJsonPath('data.0.sourceOfFund', 'Library budget')
            ->assertJsonPath('data.0.cost', '99.00')
            ->assertJsonPath('data.0.remarks', 'Rush order');
    }

    public function test_a_student_cannot_add_copies(): void
    {
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)
            ->postJson("/api/librarian/books/{$this->book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => 'X', 'cost' => 1])
            ->assertForbidden();
    }
}
