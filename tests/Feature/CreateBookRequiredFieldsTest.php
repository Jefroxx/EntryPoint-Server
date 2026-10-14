<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A new book's record must be complete: only the remarks may be left out.
 */
class CreateBookRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Science']);

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'The Origin of Species',
            'authors'         => [['name' => 'Charles Darwin', 'role' => 'Author']],
            'subjectName'     => 'Science',
            'quantity'        => 1,
            'isbn'            => '978-0-14-043205-3',
            'publicationYear' => 1859,
            'publisher'       => 'John Murray',
            'edition'         => '1st ed.',
            'volume'          => 'N/A',
            'pages'           => 502,
            'sourceOfFund'    => 'Library budget',
            'cost'            => 450,
        ], $overrides);
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->payload($overrides));
    }

    public function test_a_complete_book_is_created_without_remarks(): void
    {
        $this->create()->assertCreated();

        $book = Book::where('title', 'The Origin of Species')->firstOrFail();
        $this->assertSame('John Murray', $book->publisher);
        $this->assertSame('Library budget', $book->sourceOfFund);
        $this->assertNull($book->remarks);
    }

    public function test_remarks_are_still_accepted(): void
    {
        $this->create(['remarks' => 'Donated copy'])->assertCreated();

        $this->assertSame('Donated copy', Book::where('title', 'The Origin of Species')->firstOrFail()->remarks);
    }

    public function test_the_copy_number_is_gone_from_the_catalog(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('books', 'copyNumber'));

        // An old client that still sends one is simply ignored, not rejected.
        $this->create(['copyNumber' => 'c.1'])->assertCreated()->assertJsonMissingPath('book.copyNumber');
    }

    /** @dataProvider requiredFields */
    public function test_each_required_field_is_enforced(string $field): void
    {
        $this->create([$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);

        $payload = $this->payload();
        unset($payload[$field]);
        $this->actingAs($this->librarian)->postJson('/api/librarian/books', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseMissing('books', ['title' => 'The Origin of Species']);
    }

    public static function requiredFields(): array
    {
        return array_map(fn (string $f) => [$f], ['isbn', 'publicationYear', 'publisher', 'edition', 'volume', 'pages', 'sourceOfFund', 'cost']);
    }

    public function test_a_donated_book_can_cost_zero_but_not_less(): void
    {
        $this->create(['cost' => 0])->assertCreated();
        $this->create(['title' => 'Another', 'cost' => -5])->assertUnprocessable()->assertJsonValidationErrors('cost');
    }

    // ---- editing a book holds it to the same record ----

    private function existingBook(): Book
    {
        return Book::factory()->create([
            'isbn' => '9780140432053', 'publicationYear' => 1859, 'publisher' => 'John Murray', 'edition' => '1st ed.',
            'volume' => 'N/A', 'pages' => 502, 'sourceOfFund' => 'Library budget', 'cost' => 450,
        ]);
    }

    /** @dataProvider requiredFields */
    public function test_an_edit_cannot_blank_out_a_required_field(string $field): void
    {
        $book = $this->existingBook();
        $before = $book->fresh()->getAttribute($field);

        foreach ([null, ''] as $blank) {
            $this->actingAs($this->librarian)
                ->patchJson("/api/librarian/books/{$book->uuid}", [$field => $blank])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertEquals($before, $book->fresh()->getAttribute($field));
    }

    public function test_an_edit_that_keeps_the_record_complete_goes_through(): void
    {
        $book = $this->existingBook();

        $this->actingAs($this->librarian)
            ->patchJson("/api/librarian/books/{$book->uuid}", ['title' => 'Renamed', 'publisher' => 'Penguin', 'cost' => 0])
            ->assertOk();

        $book->refresh();
        $this->assertSame('Renamed', $book->title);
        $this->assertSame('Penguin', $book->publisher);
        $this->assertSame('0.00', (string) $book->cost);
    }

    public function test_the_book_detail_sends_the_page_count_apart_from_the_page_photos(): void
    {
        $book = $this->existingBook();

        // `pages` is the list of page photos; the number of pages used to be lost behind it.
        $this->actingAs($this->librarian)->getJson("/api/librarian/books/{$book->uuid}")
            ->assertOk()
            ->assertJsonPath('book.pageCount', 502)
            ->assertJsonPath('book.pages', []);
    }

    public function test_the_remarks_can_still_be_cleared_when_editing(): void
    {
        $book = $this->existingBook();
        $book->update(['remarks' => 'Water-stained cover']);

        $this->actingAs($this->librarian)->patchJson("/api/librarian/books/{$book->uuid}", ['remarks' => null])->assertOk();

        $this->assertNull($book->fresh()->remarks);
    }
}
