<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\BookSuggestion;
use App\Models\FundSource;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Smaller rules of the Library page: fund names, protected areas, rejection reasons, copies of new books. */
class LibraryCleanupTest extends TestCase
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

    // ---- sources of funds ----

    public function test_wording_that_means_donated_is_the_same_source(): void
    {
        $book = Book::factory()->create();

        foreach (['Donation', 'donated', 'Donated by the class of 2025', 'DONOR'] as $typed) {
            $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => $typed, 'cost' => 0])->assertCreated();
        }

        $this->assertSame(4, BookCopy::where('bookID', $book->bookID)->where('sourceOfFund', 'Donated')->count());
        $this->assertSame(1, FundSource::where('name', 'Donated')->count());   // no second "Donation" on the list
    }

    public function test_a_known_source_typed_loosely_uses_the_listed_name_and_a_new_one_joins_the_list(): void
    {
        $book = Book::factory()->create();

        $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => ' library  BUDGET ', 'cost' => 100])->assertCreated();
        $this->assertSame('Library budget', BookCopy::where('bookID', $book->bookID)->latest('copyID')->first()->sourceOfFund);

        $this->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => 'alumni association', 'cost' => 100])->assertCreated();
        $this->assertSame('Alumni association', BookCopy::where('bookID', $book->bookID)->latest('copyID')->first()->sourceOfFund);
        $this->assertTrue(FundSource::where('name', 'Alumni association')->exists());

        // Typed again, it is the same source, not another one.
        $this->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => 'Alumni Associations', 'cost' => 100])->assertCreated();
        $this->assertSame(1, FundSource::whereIn('name', ['Alumni association', 'Alumni Associations'])->count());
    }

    public function test_the_copies_of_a_new_book_carry_its_source_of_fund_and_cost(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/books', [
            'title' => 'Fresh Book', 'authors' => [['name' => 'Ana Reyes', 'role' => 'Author']], 'subjectName' => 'Science', 'quantity' => 2,
            'isbn' => '9780262033848', 'publisher' => 'Acme Press', 'edition' => '1st ed.', 'volume' => 'N/A', 'pageCount' => 200,
            'sourceOfFund' => 'Student Council fund', 'cost' => 120, 'areaOfLibrary' => 'circulation', 'publicationYear' => 2020,
        ])->assertCreated();

        $copies = BookCopy::whereHas('book', fn ($q) => $q->where('title', 'Fresh Book'))->get();
        $this->assertCount(2, $copies);
        foreach ($copies as $copy) {
            $this->assertSame('Student Council fund', $copy->sourceOfFund);
            $this->assertEquals(120, $copy->cost);
        }
        $this->assertTrue(FundSource::where('name', 'Student Council fund')->exists());
    }

    // ---- areas ----

    public function test_the_areas_the_system_relies_on_cannot_be_deleted(): void
    {
        $areas = $this->actingAs($this->librarian)->getJson('/api/librarian/areas')->json('areas');
        $this->assertTrue(collect($areas)->firstWhere('key', 'circulation')['locked']);
        $this->assertFalse(collect($areas)->firstWhere('key', 'dictionary')['locked']);

        foreach (['circulation', 'reserved', 'filipiniana'] as $key) {
            $this->deleteJson('/api/librarian/areas/'.LoanPeriod::find($key)->uuid)->assertUnprocessable();
            $this->assertNotNull(LoanPeriod::find($key));
        }

        // An empty area that nothing leans on can go.
        $this->deleteJson('/api/librarian/areas/'.LoanPeriod::find('dictionary')->uuid)->assertOk();
    }

    // ---- book requests ----

    public function test_a_rejection_needs_a_reason_and_the_student_is_told_it(): void
    {
        $suggestion = BookSuggestion::factory()->create([
            'studentID' => Student::factory()->create()->studentID, 'title' => 'Clean Code', 'status' => 'Pending', 'progressStep' => 'Submitted',
        ]);

        $this->actingAs($this->librarian)->postJson("/api/librarian/book-suggestions/{$suggestion->uuid}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('Pending', $suggestion->fresh()->status);

        $this->postJson("/api/librarian/book-suggestions/{$suggestion->uuid}/reject", ['reason' => 'We already have a newer edition on order.'])->assertOk();

        $this->assertSame('We already have a newer edition on order.', $suggestion->fresh()->rejectionReason);
        $note = SystemNotification::where('userID', $suggestion->studentID)->where('type', 'book_suggestion_rejected')->firstOrFail();
        $this->assertStringContainsString('We already have a newer edition on order.', $note->message);
    }
}
