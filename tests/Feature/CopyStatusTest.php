<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
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

    public function test_students_cannot_edit_copies(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)
            ->patchJson("/api/librarian/copies/{$copy->uuid}", ['status' => 'lost'])
            ->assertForbidden();
    }
}
