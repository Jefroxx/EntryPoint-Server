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

/** The checkout desk scans a book's barcode first; this finds the copy and says whether it can be lent. */
class CopyLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        LoanPeriod::updateOrCreate(['area' => 'reserved'], ['loanable' => false, 'periodValue' => 0, 'periodUnit' => 'days']);

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_a_scanned_barcode_finds_its_copy_and_book(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$copy->barcodeValue)
            ->assertOk()
            ->assertJsonPath('copy.copyID', $copy->copyID)
            ->assertJsonPath('copy.book.title', $this->book->title)
            ->assertJsonPath('copy.lendable', null);
    }

    public function test_the_lookup_says_when_the_book_would_fall_due(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $due = $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$copy->barcodeValue)
            ->assertOk()->json('copy.dueDate');

        $this->assertNotNull($due);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($due)->isSameDay(now()->addDays(3)));
    }

    public function test_a_library_use_only_book_is_caught_at_the_scan(): void
    {
        $reference = Book::factory()->create(['areaOfLibrary' => 'reserved']);
        $copy = BookCopy::factory()->create(['bookID' => $reference->bookID]);

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$copy->barcodeValue)
            ->assertOk()
            ->assertJsonPath('copy.lendable', 'This book is for library use only and cannot be loaned out.')
            ->assertJsonPath('copy.dueDate', null);
    }

    public function test_the_accession_number_works_when_typed(): void
    {
        $copy = BookCopy::factory()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$copy->accessionNumber)
            ->assertOk()
            ->assertJsonPath('copy.copyID', $copy->copyID);
    }

    public function test_a_copy_that_cannot_be_lent_says_why(): void
    {
        $out = BookCopy::factory()->borrowed()->create(['bookID' => $this->book->bookID]);
        $broken = BookCopy::factory()->damaged()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$out->barcodeValue)
            ->assertOk()
            ->assertJsonPath('copy.lendable', 'This copy is already out on loan.');

        $this->actingAs($this->librarian)
            ->getJson('/api/librarian/copies/lookup?code='.$broken->barcodeValue)
            ->assertOk()
            ->assertJsonPath('copy.lendable', 'This copy is marked damaged and cannot be lent.');
    }

    public function test_an_unknown_or_removed_copy_is_not_found(): void
    {
        $retired = BookCopy::factory()->retired()->create(['bookID' => $this->book->bookID]);

        $this->actingAs($this->librarian)->getJson('/api/librarian/copies/lookup?code=BK-NOPE')
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($this->librarian)->getJson('/api/librarian/copies/lookup?code='.$retired->barcodeValue)
            ->assertUnprocessable();
    }

    public function test_students_cannot_use_the_desk_lookup(): void
    {
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)->getJson('/api/librarian/copies/lookup?code=BK-X')->assertForbidden();
    }
}
