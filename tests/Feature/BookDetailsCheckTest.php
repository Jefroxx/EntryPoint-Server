<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Check Details: the online catalogues fill what an imported book's record lacks, after a librarian accepts it. */
class BookDetailsCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['classification.google_books.enabled' => false]);
        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    /** A book as the accession record import leaves it: title, author and year, the rest empty. */
    private function imported(string $title, ?string $author, ?int $year, array $extra = []): Book
    {
        $book = Book::factory()->create(array_merge([
            'title' => $title, 'publicationYear' => $year, 'isbn' => null, 'publisher' => null, 'pageCount' => null, 'edition' => null,
            'volume' => null, 'coverImageURL' => null, 'deweyNumber' => null, 'cutter' => null, 'deweyStatus' => 'needs_review', 'detailsCheckedAt' => null,
        ], $extra));
        if ($author) {
            $book->authors()->attach(Author::firstOrCreate(['name' => $author], ['uuid' => Str::uuid()])->authorID, ['uuid' => Str::uuid(), 'role' => null]);
        }

        return $book;
    }

    /** Open Library's search answer for works [title, author, isbn, year, publisher]. */
    private function search(array $works): array
    {
        return ['docs' => array_map(fn ($w) => [
            'title' => $w[0], 'author_name' => [$w[1]],
            'editions' => ['docs' => [['title' => $w[0], 'isbn' => [$w[2]], 'publish_year' => [$w[3]], 'publisher' => [$w[4]]]]],
        ], $works)];
    }

    public function test_a_book_without_an_isbn_is_found_by_title_and_author_and_only_its_empty_fields_are_offered(): void
    {
        Http::fake([
            'openlibrary.org/search.json*' => Http::response($this->search([
                ['Human Biology', 'Sylvia S. Mader', '9780073884004', 2004, 'McGraw-Hill'],
                ['Biology', 'Sylvia S. Mader', '9780073212043', 2004, 'McGraw-Hill College'],
            ])),
            '*' => Http::response('', 404), // the ISBN lookups find nothing more: the search's own details are used
        ]);
        $book = $this->imported('Biology', 'Mader, Sylvia S.', 2004, ['pageCount' => 1009]);

        $result = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/details/check")->assertOk()->json();

        $this->assertTrue($result['found']);
        $this->assertSame('title', $result['matchedBy']);
        // "Biology", not "Human Biology": the exact title wins.
        $this->assertSame('9780073212043', $result['fills']['isbn']);
        $this->assertSame('McGraw-Hill College', $result['fills']['publisher']);
        $this->assertTrue($result['yearAgrees']);
        // The record already has the pages: never offered again.
        $this->assertArrayNotHasKey('pageCount', $result['fills']);
        // Nothing was saved.
        $this->assertNull($book->fresh()->isbn);
    }

    public function test_a_misspelled_author_still_finds_the_book_but_a_same_titled_book_by_someone_else_does_not(): void
    {
        Http::fake([
            'openlibrary.org/search.json?*author=*' => Http::response(['docs' => []]),
            'openlibrary.org/search.json*' => Http::response($this->search([
                ['Operating system concepts', 'James L. Peterson', '9780201060898', 1985, 'Addison-Wesley'],
                ['Operating system concepts', 'Abraham Silberschatz', '9780471417439', 2002, 'John Wiley & Sons'],
            ])),
            '*' => Http::response('', 404),
        ]);
        $book = $this->imported('Operating system concepts', 'Silberchatz, Abraham', 2002);

        $result = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/details/check")->json();
        $this->assertSame('9780471417439', $result['fills']['isbn']);

        $other = $this->imported('Operating system concepts', 'Santos, Maria', 2002);
        $this->assertFalse($this->postJson("/api/librarian/books/{$other->uuid}/details/check")->json('found'));
    }

    public function test_accepting_fills_only_empty_fields_marks_books_checked_and_refuses_an_isbn_another_book_has(): void
    {
        $book = $this->imported('Biology', 'Mader, Sylvia S.', 2004, ['publisher' => 'Typed by the librarian']);
        $twin = $this->imported('Biology (Filipiniana copy)', 'Mader, Sylvia S.', 2004);
        $untouched = $this->imported('Not Found Anywhere', null, null);

        $this->actingAs($this->librarian)->postJson('/api/librarian/books/details/apply', [
            'items' => [
                ['book' => $book->uuid, 'fills' => ['isbn' => '978-0-07-321204-3', 'publisher' => 'McGraw-Hill', 'publicationYear' => 2004, 'pageCount' => 1009, 'deweyNumber' => '570', 'deweySource' => 'library_of_congress']],
                // The same ISBN for a second record: refused (the same ISBN is the same book).
                ['book' => $twin->uuid, 'fills' => ['isbn' => '9780073212043', 'pageCount' => 1009]],
                // Nothing found, but the librarian typed the Dewey number.
                ['book' => $untouched->uuid, 'fills' => ['deweyNumber' => '001.4']],
            ],
            'checked' => [$book->uuid, $twin->uuid, $untouched->uuid],
        ])->assertOk()->assertJsonPath('refusedIsbns', ['Biology (Filipiniana copy)']);

        $book->refresh();
        $this->assertSame('9780073212043', $book->isbn);
        $this->assertSame('Typed by the librarian', $book->publisher); // never overwritten
        $this->assertSame(1009, $book->pageCount);
        $this->assertSame('570', $book->deweyNumber);
        $this->assertSame('ok', $book->deweyStatus);
        $this->assertNotNull($book->detailsCheckedAt);

        $twin->refresh();
        $this->assertNull($twin->isbn);
        $this->assertSame(1009, $twin->pageCount);

        $untouched->refresh();
        $this->assertSame('001.4', $untouched->deweyNumber);
        $this->assertSame('manual', $untouched->deweySource);

        // Checked books leave the list; a book not checked yet stays on it.
        $waiting = $this->imported('Still Waiting', 'Reyes, Ana', 2010);
        $this->getJson('/api/librarian/books/details-check')->assertOk()
            ->assertJsonPath('remaining', 1)->assertJsonPath('books.data.0.uuid', (string) $waiting->uuid);
    }

    public function test_a_book_with_an_isbn_is_looked_up_by_it(): void
    {
        Http::fake([
            'openlibrary.org/api/books*' => Http::response(['ISBN:9780262033848' => [
                'title' => 'Introduction to Algorithms', 'publishers' => [['name' => 'MIT Press']], 'number_of_pages' => 1292, 'publish_date' => '2009',
            ]]),
            '*' => Http::response('', 404),
        ]);
        $book = $this->imported('Introduction to Algorithms', 'Cormen, Thomas', null, ['isbn' => '9780262033848']);

        $result = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/details/check")->json();

        $this->assertSame('isbn', $result['matchedBy']);
        $this->assertTrue($result['titleAgrees']);
        $this->assertSame('MIT Press', $result['fills']['publisher']);
        $this->assertSame(1292, $result['fills']['pageCount']);
        $this->assertArrayNotHasKey('isbn', $result['fills']);
    }

    public function test_a_scanned_isbn_is_looked_up_for_that_book_and_kept_even_when_no_catalogue_knows_it(): void
    {
        Http::fake([
            // The catalogue adds a subtitle the record leaves out: still the same book.
            'openlibrary.org/api/books*' => Http::response(['ISBN:9780073212043' => ['title' => 'Biology: Florida Advanced Placement edition', 'publishers' => [['name' => 'McGraw-Hill']], 'number_of_pages' => 1009]]),
            '*' => Http::response('', 404),
        ]);
        $book = $this->imported('Biology', 'Mader, Sylvia S.', 2004);

        $result = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/details/check", ['isbn' => '978-0-07-321204-3'])->json();
        $this->assertSame('scan', $result['matchedBy']);
        $this->assertTrue($result['titleAgrees']);
        $this->assertSame('9780073212043', $result['fills']['isbn']);
        $this->assertSame('McGraw-Hill', $result['fills']['publisher']);

        // No catalogue knows this one, but it came off the book: the ISBN itself is still offered.
        $other = $this->imported('A Local Workbook', 'Santos, Ana', 2024);
        $unknown = $this->postJson("/api/librarian/books/{$other->uuid}/details/check", ['isbn' => '9786214271450'])->json();
        $this->assertTrue($unknown['found']);
        $this->assertFalse($unknown['catalogueFound']);
        $this->assertSame(['isbn' => '9786214271450'], $unknown['fills']);

        // Not an ISBN at all.
        $this->assertFalse($this->postJson("/api/librarian/books/{$other->uuid}/details/check", ['isbn' => '12345'])->json('found'));
    }

    public function test_a_mistyped_isbn_that_is_another_book_s_is_shown_as_a_different_title(): void
    {
        Http::fake([
            'openlibrary.org/api/books*' => Http::response(['ISBN:9780262033848' => ['title' => 'Adapt or Die: The Guide to Successful Consulting']]),
            '*' => Http::response('', 404),
        ]);
        $book = $this->imported('A Practical Guide to Comparative Religion', 'Terry, Lucie', 2022, ['isbn' => '9780262033848']);

        $result = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/details/check")->json();

        $this->assertTrue($result['found']);
        $this->assertFalse($result['titleAgrees']);
    }
}
