<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessionNumberTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        // BookFactory files each book under an existing subject.
        BookSubject::factory()->create();
        $this->book = Book::factory()->create();
    }

    public function test_copies_are_numbered_one_two_three(): void
    {
        $copies = BookCopy::factory()->count(3)->create(['bookID' => $this->book->bookID]);

        $this->assertSame([1, 2, 3], $copies->pluck('accessionNumber')->all());
    }

    public function test_a_new_copy_follows_the_highest_number_even_after_a_gap(): void
    {
        BookCopy::factory()->create(['bookID' => $this->book->bookID, 'accessionNumber' => 1]);
        BookCopy::factory()->create(['bookID' => $this->book->bookID, 'accessionNumber' => 7]);

        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->assertSame(8, $copy->accessionNumber);
    }

    public function test_a_book_keeps_its_area_of_the_library(): void
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'filipiniana']);

        $this->assertSame('filipiniana', $book->fresh()->areaOfLibrary);
    }
}
