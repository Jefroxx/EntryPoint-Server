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

/** Barcode labels can be printed for chosen copies, one book's copies, or everything a filter matches. */
class CopyLabelsTest extends TestCase
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

    public function test_labels_for_chosen_copies_carry_the_barcode_and_book(): void
    {
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'filipiniana', 'deweyStatus' => 'ok', 'deweyNumber' => '899.211', 'cutter' => 'R59n']);
        $a = BookCopy::factory()->create(['bookID' => $book->bookID]);
        $b = BookCopy::factory()->create(['bookID' => $book->bookID]);
        BookCopy::factory()->create(['bookID' => $book->bookID]);   // not chosen

        $labels = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['copies' => [$b->uuid, $a->uuid]])
            ->assertOk()
            ->assertJsonCount(2, 'labels')
            ->json('labels');

        $this->assertSame($a->barcodeValue, $labels[0]['barcodeValue']);   // accession order, not the order asked for
        $this->assertSame('Noli Me Tangere', $labels[0]['title']);
        $this->assertSame('899.211', $labels[0]['callNumber']);   // the call number is the Dewey number; the Cutter is its own field
        $this->assertSame('Filipiniana', $labels[0]['area']);
        // The lines of the printed label, top to bottom: area, call number (Dewey), author Cutter, year.
        $this->assertSame('899.211', $labels[0]['dewey']);
        $this->assertSame('R59n', $labels[0]['cutter']);
        $this->assertSame($book->publicationYear, $labels[0]['year']);
    }

    public function test_a_guessed_call_number_is_left_off_the_label(): void
    {
        $book = Book::factory()->create(['deweyStatus' => 'needs_review', 'deweyNumber' => '254.52', 'cutter' => 'Z##']);
        BookCopy::factory()->create(['bookID' => $book->bookID]);

        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['book' => $book->uuid])
            ->assertOk()
            ->assertJsonPath('labels.0.callNumber', null)
            ->assertJsonPath('labels.0.dewey', null)
            ->assertJsonPath('labels.0.cutter', null);
    }

    public function test_the_librarian_can_choose_to_print_a_guessed_number_anyway(): void
    {
        $guessed = Book::factory()->create(['deweyStatus' => 'needs_review', 'deweyNumber' => '254.52', 'cutter' => 'Z##', 'publicationYear' => 2011]);
        $checked = Book::factory()->create(['deweyStatus' => 'ok', 'deweyNumber' => '005.1', 'cutter' => 'C67']);
        BookCopy::factory()->create(['bookID' => $guessed->bookID]);
        BookCopy::factory()->create(['bookID' => $checked->bookID]);

        // Off (the default): the guess stays off the label, and the label says so with `unconfirmed`.
        $labels = $this->actingAs($this->librarian)->postJson('/api/librarian/copies/labels', [])->assertOk()->json('labels');
        $this->assertNull($labels[0]['dewey']);
        $this->assertNull($labels[0]['cutter']);
        $this->assertTrue($labels[0]['unconfirmed']);
        $this->assertSame(2011, $labels[0]['year']);                  // the rest of the label still prints
        $this->assertSame('005.1', $labels[1]['dewey']);
        $this->assertFalse($labels[1]['unconfirmed']);

        // On: the guess is printed, still flagged; a confirmed number is unchanged.
        $labels = $this->actingAs($this->librarian)->postJson('/api/librarian/copies/labels', ['includeUnconfirmed' => true])->assertOk()->json('labels');
        $this->assertSame('254.52', $labels[0]['dewey']);
        $this->assertSame('Z##', $labels[0]['cutter']);
        $this->assertSame('254.52', $labels[0]['callNumber']);
        $this->assertTrue($labels[0]['unconfirmed']);
        $this->assertSame('005.1', $labels[1]['dewey']);

        $this->actingAs($this->librarian)->postJson('/api/librarian/copies/labels', ['includeUnconfirmed' => 'maybe'])->assertUnprocessable();
    }

    public function test_every_copy_of_a_book_and_nothing_removed(): void
    {
        $book = Book::factory()->create();
        BookCopy::factory()->count(3)->create(['bookID' => $book->bookID]);
        BookCopy::factory()->retired()->create(['bookID' => $book->bookID]);
        BookCopy::factory()->create();   // another book

        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['book' => $book->uuid])
            ->assertOk()
            ->assertJsonCount(3, 'labels')
            ->assertJsonPath('truncated', false);
    }

    public function test_the_catalog_filters_pick_the_copies(): void
    {
        $match = Book::factory()->create(['title' => 'Zebra Handbook']);
        BookCopy::factory()->count(2)->create(['bookID' => $match->bookID]);
        BookCopy::factory()->create(['bookID' => Book::factory()->create(['title' => 'Something Else'])->bookID]);

        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['search' => 'Zebra'])
            ->assertOk()
            ->assertJsonCount(2, 'labels')
            ->assertJsonPath('total', 2);
    }

    public function test_accession_ranges_and_single_numbers_pick_the_copies(): void
    {
        $book = Book::factory()->create();
        $copies = BookCopy::factory()->count(12)->create(['bookID' => $book->bookID]);
        $first = $copies->first()->accessionNumber;

        // "first to first+2, first+5, first+9 to first+10"
        $labels = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['accessions' => [
                ['from' => $first, 'to' => $first + 2],
                ['from' => $first + 5, 'to' => $first + 5],
                ['from' => $first + 9, 'to' => $first + 10],
            ]])
            ->assertOk()
            ->assertJsonCount(6, 'labels')
            ->json('labels');

        $this->assertSame(
            [$first, $first + 1, $first + 2, $first + 5, $first + 9, $first + 10],
            array_column($labels, 'accessionNumber'),
        );
    }

    public function test_the_numbers_narrow_a_book_and_a_backwards_range_is_refused(): void
    {
        $book = Book::factory()->create();
        $copies = BookCopy::factory()->count(4)->create(['bookID' => $book->bookID]);
        $other = BookCopy::factory()->create();   // another book, but its number is in range too
        $first = $copies->first()->accessionNumber;

        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['book' => $book->uuid, 'accessions' => [['from' => $first, 'to' => $other->accessionNumber]]])
            ->assertOk()
            ->assertJsonCount(4, 'labels');

        $this->actingAs($this->librarian)
            ->postJson('/api/librarian/copies/labels', ['accessions' => [['from' => 10, 'to' => 5]]])
            ->assertUnprocessable();
    }

    public function test_students_cannot_print_labels(): void
    {
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)->postJson('/api/librarian/copies/labels')->assertForbidden();
    }
}
