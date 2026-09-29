<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $this->librarian = User::factory()->create(['userType' => 'librarian', 'firstName' => 'Rosa', 'middleInitial' => null, 'lastName' => 'Dela Cruz']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid(), 'name' => 'Overdue (circulation)']);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 0]);
    }

    public function test_checkout_returns_a_receipt_with_the_book_due_date_and_fine(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'studentIDNumber' => '02-2024-001']);
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'publisher' => 'Anvil', 'edition' => '2nd', 'areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $this->travelTo(now()->setTime(10, 0));

        $receipt = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $student->studentID, 'copyID' => $copy->copyID])
            ->assertCreated()
            ->json('receipt');

        $this->assertMatchesRegularExpression('/^L-\d{6}$/', $receipt['receiptNumber']);
        $this->assertSame('02-2024-001', $receipt['student']['studentIDNumber']);
        $this->assertSame('Noli Me Tangere', $receipt['book']['title']);
        $this->assertSame('Anvil', $receipt['book']['publisher']);
        $this->assertSame('2nd', $receipt['book']['edition']);
        $this->assertSame($copy->accessionNumber, $receipt['book']['accessionNumber']);
        $this->assertSame(now()->addDays(3)->toDateString(), substr($receipt['dueDate'], 0, 10));
        $this->assertEquals(['rate' => 5.0, 'rateUnit' => 'day'], $receipt['fine']);
        $this->assertSame('Rosa Dela Cruz', $receipt['printedBy']);
    }

    public function test_a_student_can_open_their_own_receipt_but_not_someone_elses(): void
    {
        $owner = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create(['title' => 'El Filibusterismo', 'areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $loanID = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $owner->studentID, 'copyID' => $copy->copyID])
            ->json('loan.loanID');

        $this->actingAs($owner->user)
            ->getJson("/api/student/loans/{$loanID}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt.book.title', 'El Filibusterismo')
            ->assertJsonPath('receipt.printedBy', null);

        $this->actingAs($other->user)->getJson("/api/student/loans/{$loanID}/receipt")->assertNotFound();
    }

    public function test_a_receipt_can_be_reprinted_and_students_cannot_fetch_it(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID]);

        $loanID = $this->actingAs($this->librarian)
            ->postJson('/api/librarian/loans', ['studentID' => $student->studentID, 'copyID' => $copy->copyID])
            ->json('loan.loanID');

        $this->getJson("/api/librarian/loans/{$loanID}/receipt")->assertOk()->assertJsonPath('receipt.book.title', $book->title);

        $this->actingAs($student->user)->getJson("/api/librarian/loans/{$loanID}/receipt")->assertForbidden();
    }
}
