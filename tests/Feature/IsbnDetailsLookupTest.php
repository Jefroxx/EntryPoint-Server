<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\User;
use App\Services\BookMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Filling the Add Book form from an ISBN scan: title, authors, publisher, pages, cover and Dewey from three catalogues. */
class IsbnDetailsLookupTest extends TestCase
{
    use RefreshDatabase;

    private const ISBN = '9780262033848';

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function loc(): string
    {
        return '<?xml version="1.0"?><zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:records><zs:record><zs:recordData>'
            . '<record xmlns="http://www.loc.gov/MARC21/slim"><controlfield tag="008">090226s2009    maua</controlfield>'
            . '<datafield tag="082" ind1="0" ind2="0"><subfield code="a">005.1</subfield></datafield>'
            . '<datafield tag="100" ind1="1" ind2=" "><subfield code="a">Cormen, Thomas H.</subfield></datafield>'
            . '<datafield tag="245" ind1="1" ind2="0"><subfield code="a">Introduction to algorithms /</subfield><subfield code="c">Thomas H. Cormen ...</subfield></datafield>'
            . '<datafield tag="264" ind1=" " ind2="1"><subfield code="a">Cambridge, Mass. :</subfield><subfield code="b">MIT Press,</subfield><subfield code="c">c2009.</subfield></datafield>'
            . '<datafield tag="300" ind1=" " ind2=" "><subfield code="a">1292 p. :</subfield></datafield>'
            . '</record></zs:recordData></zs:record></zs:records></zs:searchRetrieveResponse>';
    }

    private function openLibrary(): array
    {
        return ['ISBN:' . self::ISBN => [
            'title'     => 'Introduction to Algorithms',
            'authors'   => [['name' => 'Thomas H. Cormen']],
            'publishers' => [['name' => 'MIT Press']],
            'publish_date' => 'July 31, 2009',
            'number_of_pages' => 1312,
            'cover'     => ['large' => 'http://covers.openlibrary.org/b/id/123-L.jpg'],
        ]];
    }

    private function google(): array
    {
        return ['items' => [['volumeInfo' => [
            'title'       => 'Introduction to Algorithms, Third Edition',
            'authors'     => ['Thomas H. Cormen', 'Charles E. Leiserson'],
            'publisher'   => 'MIT Press',
            'publishedDate' => '2009-07-31',
            'description' => 'A comprehensive update of the leading algorithms text.',
            'pageCount'   => 1292,
            'imageLinks'  => ['thumbnail' => 'http://books.google.com/books/content?id=abc&printsec=frontcover&img=1&zoom=1&edge=curl'],
        ]]]];
    }

    private function lookup(string $isbn = self::ISBN)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/isbn/lookup', ['isbn' => $isbn]);
    }

    public function test_isbns_are_checked_and_isbn_10_is_converted_to_13(): void
    {
        $this->assertSame(self::ISBN, BookMetadataService::normalizeIsbn('978-0-262-03384-8'));
        $this->assertSame(self::ISBN, BookMetadataService::normalizeIsbn('0-262-03384-4'));
        $this->assertSame('9780306406157', BookMetadataService::normalizeIsbn('0306406152'));
        // A wrong check digit, a price add-on, and rubbish are not ISBNs.
        $this->assertNull(BookMetadataService::normalizeIsbn('9780262033849'));
        $this->assertNull(BookMetadataService::normalizeIsbn('90000'));
        $this->assertNull(BookMetadataService::normalizeIsbn('hello'));
        $this->assertNull(BookMetadataService::normalizeIsbn(null));
    }

    public function test_the_three_catalogues_are_merged_with_the_library_of_congress_trusted_first(): void
    {
        Http::fake([
            'lx2.loc.gov*'       => Http::response($this->loc()),
            'openlibrary.org/*'   => Http::response($this->openLibrary()),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        $this->lookup()->assertOk()->assertJson([
            'found'       => true,
            'isbn'        => self::ISBN,
            // From the Library of Congress, with the catalogue punctuation cleaned off and the name turned round.
            'title'       => 'Introduction to algorithms',
            'authors'     => ['Thomas H. Cormen'],
            'publisher'   => 'MIT Press',
            'publishYear' => 2009,
            'pageCount'   => 1292,
            'deweyNumber' => '005.1',
            // Not in a MARC record, so these come from the later sources (and the cover is made https).
            'coverUrl'    => 'https://covers.openlibrary.org/b/id/123-L.jpg',
            'description' => 'A comprehensive update of the leading algorithms text.',
            'sources'     => ['library_of_congress', 'open_library', 'google_books'],
        ]);
    }

    public function test_a_source_describing_some_other_book_is_ignored(): void
    {
        // Open Library really does file spam under textbook ISBNs; its record shares nothing with the trusted title.
        Http::fake([
            'lx2.loc.gov*'       => Http::response($this->loc()),
            'openlibrary.org/*'   => Http::response(['ISBN:' . self::ISBN => [
                'title' => 'Fomo App Referral Code', 'authors' => [['name' => 'Spam Account']],
                'cover' => ['large' => 'https://covers.openlibrary.org/b/id/999-L.jpg'],
            ]]),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        $this->lookup()->assertOk()->assertJson([
            'title'    => 'Introduction to algorithms',
            'authors'  => ['Thomas H. Cormen'],
            'coverUrl' => 'https://books.google.com/books/content?id=abc&printsec=frontcover&img=1&zoom=1',
            'sources'  => ['library_of_congress', 'google_books'],
        ]);
    }

    public function test_a_cover_is_found_by_isbn_when_no_record_had_one_and_only_if_it_exists(): void
    {
        $noRecords = [
            'openlibrary.org/*'    => Http::response([]),
            'www.googleapis.com/*' => Http::response([]),
        ];

        // The cover server answers 200 the first time and 404 the second (one fake, since the first match wins).
        Http::fake([
            'lx2.loc.gov*'            => Http::response($this->loc()),
            'covers.openlibrary.org/*' => Http::sequence()->push('', 200)->push('', 404),
        ] + $noRecords);

        $this->lookup()->assertOk()->assertJson([
            'coverUrl' => 'https://covers.openlibrary.org/b/isbn/' . self::ISBN . '-L.jpg',
            'sources'  => ['library_of_congress', 'open_library'],
        ]);

        // Open Library answers 404 (not a blank picture) when it has no cover: the book is left without one.
        Cache::flush();
        $this->lookup()->assertOk()->assertJson(['coverUrl' => null]);
    }

    public function test_a_book_only_google_has_still_fills_in(): void
    {
        Http::fake([
            'lx2.loc.gov*'       => Http::response('<zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:numberOfRecords>0</zs:numberOfRecords></zs:searchRetrieveResponse>'),
            'openlibrary.org/*'   => Http::response([]),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        $this->lookup()->assertOk()->assertJson([
            'found'     => true,
            'title'     => 'Introduction to Algorithms, Third Edition',
            'authors'   => ['Thomas H. Cormen', 'Charles E. Leiserson'],
            'publishYear' => 2009,
            // The edge=curl page-curl effect is taken off Google's thumbnail and the link made https.
            'coverUrl'  => 'https://books.google.com/books/content?id=abc&printsec=frontcover&img=1&zoom=1',
            'sources'   => ['google_books'],
        ]);
    }

    public function test_a_source_that_is_down_does_not_stop_the_others(): void
    {
        Http::fake([
            'lx2.loc.gov*'       => Http::response('', 500),
            'openlibrary.org/*'   => Http::response($this->openLibrary()),
            'www.googleapis.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
        ]);

        $this->lookup()->assertOk()->assertJson(['found' => true, 'title' => 'Introduction to Algorithms', 'sources' => ['open_library']]);
    }

    public function test_a_book_no_catalogue_knows_is_a_normal_not_found_answer_not_an_error(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->lookup()->assertOk()->assertJson(['found' => false, 'reason' => 'not_found', 'isbn' => self::ISBN]);
    }

    public function test_a_bad_isbn_is_reported_without_asking_anyone(): void
    {
        Http::fake();

        $this->lookup('9780262033849')->assertOk()->assertJson(['found' => false, 'reason' => 'invalid']);

        Http::assertNothingSent();
    }

    public function test_an_answer_is_remembered_so_the_catalogues_are_asked_once(): void
    {
        Http::fake([
            'lx2.loc.gov*'       => Http::response($this->loc()),
            'openlibrary.org/*'   => Http::response($this->openLibrary()),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        $this->lookup()->assertOk();
        $sent = count(Http::recorded());
        $this->lookup()->assertOk()->assertJson(['found' => true]);

        $this->assertSame($sent, count(Http::recorded()));
    }

    public function test_only_staff_can_look_isbns_up(): void
    {
        $this->postJson('/api/librarian/isbn/lookup', ['isbn' => self::ISBN])->assertUnauthorized();
    }

    private function sourceCall(string $source, bool $retry = false)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/isbn/lookup/source', ['isbn' => self::ISBN, 'source' => $source, 'retry' => $retry]);
    }

    private const EMPTY_LOC = '<zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:numberOfRecords>0</zs:numberOfRecords></zs:searchRetrieveResponse>';

    public function test_each_catalogue_reports_whether_it_found_nothing_or_did_not_answer(): void
    {
        Http::fake([
            'lx2.loc.gov*'        => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'openlibrary.org/*'    => Http::response([]),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        $this->lookup()->assertOk()->assertJson([
            'found'        => true,
            'sourceStatus' => ['library_of_congress' => 'unreachable', 'open_library' => 'none', 'google_books' => 'found'],
            // The Library of Congress is the Dewey source and it was silent, so "no number" is not "nobody has one".
            'deweyStatus'  => 'unreachable',
        ]);
    }

    public function test_not_found_names_the_catalogue_that_stayed_silent(): void
    {
        Http::fake([
            'lx2.loc.gov*'        => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'openlibrary.org/*'    => Http::response([]),
            'www.googleapis.com/*' => Http::response([]),
        ]);

        $response = $this->lookup()->assertOk()->assertJson(['found' => false, 'reason' => 'not_found', 'sourceStatus' => ['library_of_congress' => 'unreachable']]);
        $this->assertStringContainsString("the Library of Congress didn't answer", $response->json('message'));
    }

    public function test_one_catalogue_can_be_asked_alone_and_a_failed_one_can_be_retried(): void
    {
        // Both of the Library of Congress's addresses are tried before it counts as silent: the first two requests (one
        // per address) fail, every one after that gets through.
        $requests = 0;
        Http::fake([
            'lx2.loc.gov*' => function () use (&$requests) {
                if (++$requests <= 2) {
                    throw new \Illuminate\Http\Client\ConnectionException('timed out');
                }

                return Http::response($this->loc());
            },
            '*' => Http::response([]),
        ]);

        $this->sourceCall('library_of_congress')->assertOk()->assertJson(['source' => 'library_of_congress', 'status' => 'unreachable']);

        // Without a retry the failure is remembered for a moment (so the lookup that follows doesn't wait on a dead server again)...
        $this->sourceCall('library_of_congress')->assertOk()->assertJson(['status' => 'unreachable', 'cached' => true]);

        // ...with one it asks again, and this time it gets through.
        $this->sourceCall('library_of_congress', true)->assertOk()->assertJson(['status' => 'found', 'title' => 'Introduction to algorithms']);
    }

    public function test_a_catalogue_that_has_no_record_is_none_not_unreachable(): void
    {
        Http::fake(['lx2.loc.gov*' => Http::response(self::EMPTY_LOC), '*' => Http::response([])]);

        $this->sourceCall('library_of_congress')->assertOk()->assertJson(['status' => 'none', 'title' => null]);
        $this->sourceCall('not_a_catalogue')->assertUnprocessable();
    }

    public function test_the_details_lookup_reuses_what_the_one_by_one_asks_already_got(): void
    {
        Http::fake([
            'lx2.loc.gov*'        => Http::response($this->loc()),
            'openlibrary.org/*'    => Http::response($this->openLibrary()),
            'www.googleapis.com/*' => Http::response($this->google()),
        ]);

        foreach (['library_of_congress', 'open_library', 'google_books'] as $source) {
            $this->sourceCall($source, true)->assertOk()->assertJson(['status' => 'found']);
        }

        $sent = count(Http::recorded());
        $this->lookup()->assertOk()->assertJson(['found' => true, 'title' => 'Introduction to algorithms']);

        // Only the cover and Dewey checks may still go out; none of the three catalogues is asked again.
        $again = collect(Http::recorded())->slice($sent)->filter(fn ($pair) => str_contains($pair[0]->url(), 'googleapis') || str_contains($pair[0]->url(), 'api/books'));
        $this->assertCount(0, $again);
    }

    public function test_edition_and_volume_are_read_from_marc_when_the_catalogue_states_them(): void
    {
        $marc = '<?xml version="1.0"?><zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:records><zs:record><zs:recordData>'
            . '<record xmlns="http://www.loc.gov/MARC21/slim"><controlfield tag="008">090226s2009    maua</controlfield>'
            . '<datafield tag="245" ind1="1" ind2="0"><subfield code="a">Fundamentals of physics /</subfield><subfield code="n">v. 2</subfield></datafield>'
            . '<datafield tag="250" ind1=" " ind2=" "><subfield code="a">Third edition.</subfield></datafield>'
            . '<datafield tag="100" ind1="1" ind2=" "><subfield code="a">Halliday, David.</subfield></datafield>'
            . '</record></zs:recordData></zs:record></zs:records></zs:searchRetrieveResponse>';

        Http::fake(['lx2.loc.gov*' => Http::response($marc), '*' => Http::response([])]);

        $this->lookup()->assertOk()->assertJson(['found' => true, 'edition' => '3rd ed.', 'volume' => 'Vol. 2', 'hints' => []]);
    }

    public function test_an_edition_written_in_a_title_is_picked_up_but_a_bare_number_is_not(): void
    {
        // Google's title says "Third Edition" (see google()); nothing says which volume, so that stays empty for the librarian.
        Http::fake(['lx2.loc.gov*' => Http::response(self::EMPTY_LOC), 'openlibrary.org/*' => Http::response([]), 'www.googleapis.com/*' => Http::response($this->google())]);

        $this->lookup()->assertOk()->assertJson(['edition' => '3rd ed.', 'volume' => null]);
    }

    public function test_a_whole_set_is_flagged_and_notes_about_other_volumes_are_not_mistaken_for_this_one(): void
    {
        Http::fake([
            'lx2.loc.gov*'     => Http::response(self::EMPTY_LOC),
            // The real record for an encyclopedia: "7 v." (a set), and a note about volume 6's cover.
            'openlibrary.org/*' => Http::response(['ISBN:' . self::ISBN => [
                'title' => "Oxford children's encyclopedia", 'authors' => [['name' => 'Mary Worrall']],
                'pagination' => '7 v. :', 'by_statement' => '[edited by Mary Worrall]', 'notes' => 'Vol. 6 cover title: Biography.',
            ]]),
            'www.googleapis.com/*' => Http::response([]),
        ]);

        $response = $this->lookup()->assertOk()->assertJson(['found' => true, 'volume' => null]);
        $this->assertStringContainsString('set of 7 volumes', $response->json('hints.0'));
    }

    public function test_the_second_library_of_congress_address_is_used_when_the_first_does_not_answer(): void
    {
        // On 2026-11-12 the https address accepted no connections while port 210 answered; either may be the dead one.
        Http::fake([
            'lx2.loc.gov:210*'    => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'lx2.loc.gov/sru*'    => Http::response($this->loc()),
            'openlibrary.org/*'   => Http::response([]),
            'www.googleapis.com/*' => Http::response([]),
        ]);

        $this->lookup()->assertOk()->assertJson([
            'found'        => true,
            'title'        => 'Introduction to algorithms',
            'deweyNumber'  => '005.1',
            'sourceStatus' => ['library_of_congress' => 'found'],
            'deweyStatus'  => 'found',
        ]);

        // (The request that failed isn't recorded by Laravel's fake, so only the one that got through can be checked.)
        Http::assertSent(fn ($request) => str_contains($request->url(), 'lx2.loc.gov/sru'));
    }

    public function test_the_second_address_is_not_asked_when_the_first_answers(): void
    {
        Http::fake([
            'lx2.loc.gov:210*'    => Http::response($this->loc()),
            'lx2.loc.gov/sru*'    => Http::response('', 500),
            'openlibrary.org/*'   => Http::response([]),
            'www.googleapis.com/*' => Http::response([]),
        ]);

        $this->lookup()->assertOk()->assertJson(['found' => true, 'deweyNumber' => '005.1']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'lx2.loc.gov/sru'));
    }

    public function test_the_dewey_lookup_also_falls_back_to_the_second_address(): void
    {
        Http::fake([
            'lx2.loc.gov:210*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'lx2.loc.gov/sru*' => Http::response($this->loc()),
            'openlibrary.org/*' => Http::response([]),
        ]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/dewey/lookup', ['isbn' => self::ISBN])
            ->assertOk()->assertJson(['found' => true, 'deweyNumber' => '005.1', 'source' => 'library_of_congress']);
    }

    public function test_a_dewey_lookup_says_so_when_the_library_of_congress_did_not_answer_at_either_address(): void
    {
        Http::fake([
            'lx2.loc.gov*'      => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'openlibrary.org/*' => Http::response([]),
        ]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/dewey/lookup', ['isbn' => self::ISBN])
            ->assertOk()->assertJson(['found' => false, 'unreachable' => true]);

        // A second ask (inside the few minutes a failure is remembered) still knows it was "couldn't ask", not "no number".
        $this->actingAs($this->librarian)->postJson('/api/librarian/dewey/lookup', ['isbn' => self::ISBN])
            ->assertOk()->assertJson(['found' => false, 'unreachable' => true]);
    }

    public function test_a_volume_named_in_the_credit_line_is_used(): void
    {
        Http::fake([
            'lx2.loc.gov*'     => Http::response(self::EMPTY_LOC),
            'openlibrary.org/*' => Http::response(['ISBN:' . self::ISBN => [
                'title' => "Oxford children's encyclopedia", 'authors' => [['name' => 'Mary Worrall']], 'by_statement' => 'editor Mary Worrall. Vol.2',
            ]]),
            'www.googleapis.com/*' => Http::response([]),
        ]);

        $this->lookup()->assertOk()->assertJson(['volume' => 'Vol. 2', 'hints' => []]);
    }
}
