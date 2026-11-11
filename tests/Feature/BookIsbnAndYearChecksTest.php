<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Add Book / Edit Book checks on the ISBN (a real one, check digit included) and the publication year (never in the future). */
class BookIsbnAndYearChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'Introduction to Algorithms',
            'authors'         => [['name' => 'Thomas Cormen', 'role' => 'Author']],
            'subjectName'     => 'Computer Science',
            'quantity'        => 1,
            'isbn'            => '9780262033848',
            'publicationYear' => 2009,
            'publisher'       => 'MIT Press',
            'edition'         => '3rd ed.',
            'volume'          => 'N/A',
            'pageCount'       => 1292,
            'sourceOfFund'    => 'Library budget',
            'cost'            => 2500,
        ], $overrides);
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload($overrides));
    }

    public function test_real_isbns_are_accepted_in_any_usual_form(): void
    {
        $this->create(['isbn' => '9780262033848'])->assertCreated();
        // Hyphenated, ISBN-10 with a number, and an ISBN-10 ending in X (check digit 10).
        $this->create(['isbn' => '978-0-14-043205-3', 'title' => 'Moby Dick'])->assertCreated();
        $this->create(['isbn' => '0-306-40615-2', 'title' => 'Another'])->assertCreated();
        $this->create(['isbn' => '080442957X', 'title' => 'With an X'])->assertCreated();
    }

    public function test_an_isbn_with_a_wrong_check_digit_letters_or_the_wrong_length_is_refused(): void
    {
        foreach (['9780262033849', '978026203384', 'abcdefghij', '97802620338AB', '12345', '0-306-40615-3'] as $bad) {
            $this->create(['isbn' => $bad])->assertUnprocessable()->assertJsonValidationErrors('isbn');
        }
    }

    public function test_a_publication_year_cannot_be_in_the_future(): void
    {
        $this->create(['publicationYear' => now()->year])->assertCreated();
        $this->create(['publicationYear' => now()->year + 1, 'isbn' => '9780140432053'])
            ->assertUnprocessable()->assertJsonValidationErrors('publicationYear');
        $this->create(['publicationYear' => 9999, 'isbn' => '9780140432053'])->assertUnprocessable()->assertJsonValidationErrors('publicationYear');
        // Very old books are fine, but not a year that can't be four digits.
        $this->create(['publicationYear' => 1859, 'isbn' => '9780140432053'])->assertCreated();
        $this->create(['publicationYear' => 999, 'isbn' => '9780375508325'])->assertUnprocessable()->assertJsonValidationErrors('publicationYear');
    }

    public function test_editing_checks_the_isbn_and_year_too_but_leaves_an_old_unchanged_isbn_alone(): void
    {
        // A book catalogued long ago with an ISBN that would not pass today (the library has one such test entry).
        $subject = BookSubject::factory()->create();
        $book = Book::factory()->create(['subjectID' => $subject->subjectID, 'isbn' => '243242', 'publicationYear' => 2000]);

        // Editing something else, sending the same ISBN back, still saves.
        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['isbn' => '243242', 'title' => 'New title'])->assertOk();

        // Changing it to something that is not an ISBN does not.
        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['isbn' => '999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('isbn');
        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['isbn' => '9780262033848'])->assertOk();

        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['publicationYear' => now()->year + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('publicationYear');
    }
}

