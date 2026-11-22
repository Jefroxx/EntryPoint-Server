<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Scanning an ISBN the library already holds means "another copy", never a second book. */
class ExistingIsbnTest extends TestCase
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
            'quantity'        => 2,
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

    private function existing(string $isbn)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/isbn/existing', ['isbn' => $isbn]);
    }

    public function test_an_isbn_not_in_the_catalog_is_reported_as_new(): void
    {
        $this->existing('9780262033848')->assertOk()->assertJson(['exists' => false, 'book' => null]);
    }

    public function test_a_catalogued_isbn_is_found_in_any_form_with_what_the_copies_window_needs(): void
    {
        $bookUuid = $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload())->assertCreated()->json('book.uuid');

        foreach (['9780262033848', '978-0-262-03384-8', '0262033844'] as $scanned) {
            $this->existing($scanned)->assertOk()->assertJson([
                'exists' => true,
                'book'   => ['bookUuid' => $bookUuid, 'title' => 'Introduction to Algorithms', 'authors' => ['Thomas Cormen'], 'copies' => 2],
            ]);
        }
    }

    public function test_an_isbn_stored_hyphenated_is_still_matched(): void
    {
        $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload(['isbn' => '978-0-14-043205-3', 'title' => 'Moby Dick']))->assertCreated();

        $this->existing('9780140432053')->assertOk()->assertJson(['exists' => true, 'book' => ['title' => 'Moby Dick']]);
    }

    public function test_adding_a_book_whose_isbn_is_already_catalogued_is_refused_and_makes_no_second_book(): void
    {
        $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload())->assertCreated();

        // The same book again, typed with hyphens or as the ISBN-10.
        foreach (['9780262033848', '978-0-262-03384-8', '0262033844'] as $again) {
            $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload(['isbn' => $again]))
                ->assertUnprocessable()->assertJsonValidationErrors('isbn');
        }

        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('book_copies', 2);
    }
}
