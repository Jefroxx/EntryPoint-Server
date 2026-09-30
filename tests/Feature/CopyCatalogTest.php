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

class CopyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
    }

    public function test_it_lists_one_row_per_copy_in_accession_order_with_the_book_record(): void
    {
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'publisher' => 'Anvil', 'areaOfLibrary' => 'filipiniana']);
        BookCopy::factory()->count(2)->create(['bookID' => $book->bookID]);
        BookCopy::factory()->retired()->create(['bookID' => $book->bookID]);

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/copies')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.accessionNumber', 1)
            ->assertJsonPath('data.1.accessionNumber', 2)
            ->assertJsonPath('data.0.book.title', 'Noli Me Tangere')
            ->assertJsonPath('data.0.book.publisher', 'Anvil')
            ->assertJsonPath('data.0.book.areaOfLibrary', 'filipiniana');
    }

    public function test_a_number_searches_accession_numbers_exactly(): void
    {
        $book = Book::factory()->create(['title' => 'Book 1', 'classNumber' => 'QA76.1', 'isbn' => null]);
        BookCopy::factory()->count(12)->create(['bookID' => $book->bookID]);

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/copies?search=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.accessionNumber', 1);
    }

    public function test_it_filters_by_status_and_area(): void
    {
        $reserved = Book::factory()->create(['areaOfLibrary' => 'reserved']);
        $circulation = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        BookCopy::factory()->borrowed()->create(['bookID' => $reserved->bookID]);
        BookCopy::factory()->create(['bookID' => $reserved->bookID]);
        BookCopy::factory()->borrowed()->create(['bookID' => $circulation->bookID]);

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/copies?status=borrowed&area=reserved')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.book.bookID', $reserved->bookID);
    }

    public function test_students_cannot_read_it(): void
    {
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)->getJson('/api/librarian/copies')->assertForbidden();
    }
}
