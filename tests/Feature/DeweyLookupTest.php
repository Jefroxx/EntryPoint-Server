<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\User;
use App\Services\LibraryClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real Dewey numbers by ISBN (Library of Congress, then Open Library), and the "needs review" flag on everything else. */
class DeweyLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        BookSubject::factory()->create(['name' => 'Computer Science']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    /** What the Library of Congress SRU service answers for a book it has (MARC 082 Dewey, 050 LC number, 008 dates). */
    private function locRecord(?string $dewey, string $lcc = 'QA76.6 .C662 2009', string $date = '090226s2009    maua'): string
    {
        $deweyField = $dewey ? "<datafield tag=\"082\" ind1=\"0\" ind2=\"0\"><subfield code=\"a\">{$dewey}</subfield></datafield>" : '';
        [$a, $b] = explode(' .', $lcc) + [1 => ''];

        return '<?xml version="1.0"?><zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:numberOfRecords>1</zs:numberOfRecords><zs:records><zs:record><zs:recordData>'
            . '<record xmlns="http://www.loc.gov/MARC21/slim"><controlfield tag="008">' . $date . '</controlfield>'
            . $deweyField
            . "<datafield tag=\"050\" ind1=\" \" ind2=\"0\"><subfield code=\"a\">{$a}</subfield><subfield code=\"b\">.{$b}</subfield></datafield>"
            . '</record></zs:recordData></zs:record></zs:records></zs:searchRetrieveResponse>';
    }

    private function locEmpty(): string
    {
        return '<?xml version="1.0"?><zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:numberOfRecords>0</zs:numberOfRecords></zs:searchRetrieveResponse>';
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

    public function test_slashes_and_whole_call_numbers_are_cleaned_to_a_plain_dewey_number(): void
    {
        $this->assertSame('823.7', LibraryClassificationService::normalizeDewey('823/.7'));
        $this->assertSame('005.12', LibraryClassificationService::normalizeDewey('005.1/2'));
        $this->assertSame('005.1', LibraryClassificationService::normalizeDewey('005.1'));
        $this->assertSame('813.54', LibraryClassificationService::normalizeDewey('813.54 V66c 2006'));
        $this->assertNull(LibraryClassificationService::normalizeDewey('FIC'));
        $this->assertNull(LibraryClassificationService::normalizeDewey(null));
        $this->assertTrue(LibraryClassificationService::isValidDewey('005.133'));
        $this->assertFalse(LibraryClassificationService::isValidDewey('5.1'));
    }

    public function test_a_book_with_an_isbn_gets_the_library_of_congress_number_and_its_cutter(): void
    {
        Http::fake(['lx2.loc.gov*' => Http::response($this->locRecord('005.1'))]);

        $this->create()->assertCreated();

        $book = Book::where('isbn', '9780262033848')->firstOrFail();
        $this->assertSame('005.1', $book->deweyNumber);
        $this->assertSame('library_of_congress', $book->deweySource);
        $this->assertSame('ok', $book->deweyStatus);
        // The call number is the Dewey number; the Cutter the same record gave for the author is kept beside it.
        $this->assertSame('005.1', $book->callNumber);
        $this->assertSame('C662', $book->cutter);
    }

    public function test_open_library_is_used_when_the_library_of_congress_has_nothing(): void
    {
        Http::fake([
            'lx2.loc.gov*'    => Http::response($this->locEmpty()),
            'openlibrary.org/*' => Http::response(['ISBN:9780262033848' => [
                'classifications' => ['dewey_decimal_class' => ['813.54 V66c 2006']],
            ]]),
        ]);

        $this->create()->assertCreated();

        $book = Book::where('isbn', '9780262033848')->firstOrFail();
        $this->assertSame('813.54', $book->deweyNumber);
        $this->assertSame('open_library', $book->deweySource);
        $this->assertSame('ok', $book->deweyStatus);
    }

    public function test_a_number_typed_by_the_librarian_beats_the_lookup(): void
    {
        Http::fake(['lx2.loc.gov*' => Http::response($this->locRecord('005.1'))]);

        $this->create(['deweyNumber' => '004.6'])->assertCreated();

        $book = Book::where('isbn', '9780262033848')->firstOrFail();
        $this->assertSame('004.6', $book->deweyNumber);
        $this->assertSame('manual', $book->deweySource);
        $this->assertSame('ok', $book->deweyStatus);
    }

    public function test_a_book_nobody_could_classify_is_guessed_and_flagged_for_review(): void
    {
        Http::fake([
            'lx2.loc.gov*'    => Http::response($this->locEmpty()),
            'openlibrary.org/*' => Http::response([]),
        ]);

        $this->create()->assertCreated();

        $book = Book::where('isbn', '9780262033848')->firstOrFail();
        $this->assertSame('guessed', $book->deweySource);
        $this->assertSame('needs_review', $book->deweyStatus);
    }

    public function test_a_badly_formed_number_is_refused(): void
    {
        Http::fake(['*' => Http::response($this->locEmpty())]);

        $this->create(['deweyNumber' => '5.1'])->assertUnprocessable()->assertJsonValidationErrors('deweyNumber');
    }

    public function test_the_review_list_shows_unchecked_books_and_the_lookup_clears_them(): void
    {
        Http::fake([
            'lx2.loc.gov*'    => Http::response($this->locEmpty()),
            'openlibrary.org/*' => Http::response([]),
        ]);
        $this->create()->assertCreated();
        $book = Book::where('isbn', '9780262033848')->firstOrFail();

        $this->actingAs($this->librarian)->getJson('/api/librarian/books/dewey-review')
            ->assertOk()
            ->assertJsonPath('needsReview', 1)
            ->assertJsonPath('books.data.0.title', 'Introduction to Algorithms');

        // Nothing found yet: it stays on the list.
        $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/dewey/lookup")
            ->assertOk()->assertJsonPath('found', false);
        $this->assertSame('needs_review', $book->fresh()->deweyStatus);

        // The catalogue gets the record later (a miss is only cached for a day, so clear it for the test).
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake(['lx2.loc.gov*' => Http::response($this->locRecord('005.1'))]);

        $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/dewey/lookup")
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('deweyNumber', '005.1');

        $book->refresh();
        $this->assertSame('ok', $book->deweyStatus);
        $this->assertSame('library_of_congress', $book->deweySource);
        $this->assertSame('005.1', $book->callNumber);

        $this->actingAs($this->librarian)->getJson('/api/librarian/books/dewey-review')->assertJsonPath('needsReview', 0);
    }

    public function test_a_librarian_can_set_the_number_by_hand_and_the_call_number_follows(): void
    {
        Http::fake(['*' => Http::response($this->locEmpty())]);
        $this->create()->assertCreated();
        $book = Book::where('isbn', '9780262033848')->firstOrFail();

        $this->actingAs($this->librarian)->putJson("/api/librarian/books/{$book->uuid}/dewey", ['deweyNumber' => '005.13'])
            ->assertOk()->assertJsonPath('deweyNumber', '005.13');

        $book->refresh();
        $this->assertSame('manual', $book->deweySource);
        $this->assertSame('ok', $book->deweyStatus);
        $this->assertSame('005.13', $book->callNumber);

        $this->actingAs($this->librarian)->putJson("/api/librarian/books/{$book->uuid}/dewey", ['deweyNumber' => 'abc'])
            ->assertUnprocessable();
    }

    public function test_a_guessed_call_number_stays_off_what_students_see_and_off_the_label(): void
    {
        Http::fake(['lx2.loc.gov*' => Http::response($this->locEmpty()), 'openlibrary.org/*' => Http::response([])]);
        $this->create()->assertCreated();
        $book = Book::where('isbn', '9780262033848')->firstOrFail();

        $this->assertNull($book->publicCallNumber());
        $label = $book->spineLabel();
        $this->assertTrue($label['pending']);
        $this->assertNull($label['dewey']);
        $this->assertNull($label['cutter']);
        $this->assertSame('Circulation', $label['area']);
        $this->assertSame(2009, $label['year']);

        // Once a librarian confirms the number, the call number and the whole label appear.
        $this->actingAs($this->librarian)->putJson("/api/librarian/books/{$book->uuid}/dewey", ['deweyNumber' => '005.1'])->assertOk();
        $book->refresh();

        $this->assertSame($book->callNumber, $book->publicCallNumber());
        $label = $book->spineLabel();
        $this->assertFalse($label['pending']);
        $this->assertSame('005.1', $label['dewey']);
        $this->assertNotEmpty($label['cutter']);
        $this->assertSame($book->callNumber, $label['dewey']);
    }

    public function test_the_form_can_look_an_isbn_up_without_saving_anything(): void
    {
        Http::fake(['lx2.loc.gov*' => Http::response($this->locRecord('005.1'))]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/dewey/lookup', ['isbn' => '978-0-262-03384-8'])
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('deweyNumber', '005.1')
            ->assertJsonPath('cutter', 'C662')
            ->assertJsonPath('year', 2009)
            ->assertJsonPath('source', 'library_of_congress');

        $this->assertSame(0, Book::count());

        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake(['lx2.loc.gov*' => Http::response($this->locEmpty()), 'openlibrary.org/*' => Http::response([])]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/dewey/lookup', ['isbn' => '9780439708180'])
            ->assertOk()->assertJsonPath('found', false);
    }

    public function test_the_check_command_looks_up_every_flagged_book_with_an_isbn(): void
    {
        Http::fake([
            'lx2.loc.gov*'    => Http::response($this->locEmpty()),
            'openlibrary.org/*' => Http::response([]),
        ]);
        $this->create()->assertCreated();

        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake(['lx2.loc.gov*' => Http::response($this->locRecord('005.1'))]);

        $this->artisan('books:check-dewey', ['--pause' => 0])->assertSuccessful();

        $this->assertSame('ok', Book::where('isbn', '9780262033848')->firstOrFail()->deweyStatus);
    }
}
