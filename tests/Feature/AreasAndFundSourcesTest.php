<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Areas of the library (name, acronym, lending rule) and sources of funds are lists the librarian manages. */
class AreasAndFundSourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_the_areas_start_as_the_librarys_nine(): void
    {
        $areas = $this->actingAs($this->librarian)->getJson('/api/librarian/areas')->assertOk()->json('areas');

        $this->assertSame(
            ['CIR', 'FIL', 'REF', 'JOURNAL', 'T', 'FIC', 'GEN REF', 'DIC', 'MAT'],
            array_column($areas, 'acronym'),
        );
        $circulation = collect($areas)->firstWhere('key', 'circulation');
        $this->assertTrue($circulation['loanable']);
        $this->assertSame(2, $circulation['periodValue']);
    }

    public function test_an_area_can_be_added_edited_and_deleted(): void
    {
        $uuid = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/areas', ['name' => 'Rare Books', 'acronym' => 'rb', 'loanable' => false])
            ->assertCreated()
            ->assertJsonPath('area.acronym', 'RB')          // acronyms are kept in capitals
            ->assertJsonPath('area.key', 'rare-books')
            ->assertJsonPath('area.periodValue', null)       // library use only: no lending period
            ->json('area.uuid');

        $this->patchJson("/api/librarian/areas/{$uuid}", ['name' => 'Rare Books', 'acronym' => 'RB', 'loanable' => true, 'periodValue' => 6, 'periodUnit' => 'hours'])
            ->assertOk();
        $this->assertSame(6, LoanPeriod::find('rare-books')->periodValue);
        $this->assertSame('hours', LoanPeriod::find('rare-books')->periodUnit);

        $this->deleteJson("/api/librarian/areas/{$uuid}")->assertOk();
        $this->assertNull(LoanPeriod::find('rare-books'));
    }

    public function test_a_lendable_area_needs_a_period_and_names_and_acronyms_are_not_repeated(): void
    {
        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/areas', ['name' => 'Atlas', 'acronym' => 'ATL', 'loanable' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['periodValue', 'periodUnit']);

        $this->postJson('/api/librarian/areas', ['name' => 'Circulation', 'acronym' => 'ZZZ', 'loanable' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/librarian/areas', ['name' => 'Another', 'acronym' => 'CIR', 'loanable' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('acronym');
    }

    public function test_an_area_with_books_cannot_be_deleted_and_a_new_one_can_hold_books(): void
    {
        $uuid = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/areas', ['name' => 'Atlas', 'acronym' => 'ATL', 'loanable' => false])
            ->json('area.uuid');

        Book::factory()->create(['areaOfLibrary' => 'atlas']);   // the area's key is all a book stores

        $this->deleteJson("/api/librarian/areas/{$uuid}")->assertUnprocessable();
        $this->getJson('/api/librarian/areas')->assertJsonPath('areas.9.books_count', 1);
    }

    public function test_books_can_only_be_put_in_an_area_that_exists(): void
    {
        $payload = ['title' => 'T', 'isbn' => '9780262033848', 'areaOfLibrary' => 'nowhere'];
        $this->actingAs($this->librarian)->postJson('/api/librarian/books', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('areaOfLibrary');
    }

    public function test_the_label_prints_the_areas_acronym(): void
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'fiction']);
        BookCopy::factory()->create(['bookID' => $book->bookID]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/copies/labels', ['book' => $book->uuid])
            ->assertOk()->assertJsonPath('labels.0.area', 'FIC');
    }

    public function test_sources_of_funds_start_with_three_and_donated_is_fixed(): void
    {
        $sources = $this->actingAs($this->librarian)->getJson('/api/librarian/fund-sources')->assertOk()->json('sources');
        $this->assertSame(['Donated', 'Library budget', 'Student project'], array_column($sources, 'name'));
        $this->assertTrue($sources[0]['locked']);

        $this->patchJson("/api/librarian/fund-sources/{$sources[0]['uuid']}", ['name' => 'Gift'])->assertUnprocessable();
        $this->deleteJson("/api/librarian/fund-sources/{$sources[0]['uuid']}")->assertUnprocessable();
    }

    public function test_a_pressed_card_lists_what_is_connected_to_it(): void
    {
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'filipiniana']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'sourceOfFund' => 'Library budget']);
        $area = LoanPeriod::find('filipiniana');

        $this->actingAs($this->librarian)->getJson("/api/librarian/areas/{$area->uuid}/books")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.title', 'Noli Me Tangere');

        $fund = \App\Models\FundSource::where('name', 'Library budget')->first();
        $this->getJson("/api/librarian/fund-sources/{$fund->uuid}/copies")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.title', 'Noli Me Tangere')
            ->assertJsonPath('items.0.badge', (string) $copy->accessionNumber);
    }

    public function test_a_source_with_copies_cannot_be_deleted(): void
    {
        $book = Book::factory()->create();
        BookCopy::factory()->create(['bookID' => $book->bookID, 'sourceOfFund' => 'Student project']);
        $fund = \App\Models\FundSource::where('name', 'Student project')->first();

        $this->actingAs($this->librarian)->deleteJson("/api/librarian/fund-sources/{$fund->uuid}")->assertUnprocessable();
        $this->assertNotNull($fund->fresh());
    }

    public function test_renaming_a_source_updates_the_copies_that_came_from_it(): void
    {
        $uuid = $this->actingAs($this->librarian)->postJson('/api/librarian/fund-sources', ['name' => 'Alumni gift'])
            ->assertCreated()->json('source.uuid');
        $book = Book::factory()->create();
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'sourceOfFund' => 'Alumni gift']);

        $this->patchJson("/api/librarian/fund-sources/{$uuid}", ['name' => 'Alumni donation'])->assertOk();
        $this->assertSame('Alumni donation', $copy->fresh()->sourceOfFund);

        $this->postJson('/api/librarian/fund-sources', ['name' => 'Alumni donation'])->assertUnprocessable();   // no repeats
        $this->deleteJson("/api/librarian/fund-sources/{$uuid}")->assertUnprocessable();   // its copy is still connected
    }
}
