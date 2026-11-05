<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Reports page: trends, overdue records and how hard the collection is being used. */
class ReportOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    private function loan(BookCopy $copy, array $attributes = []): Loan
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        return Loan::create(array_merge([
            'uuid'         => (string) Str::uuid(),
            'studentID'    => $student->studentID,
            'copyID'       => $copy->copyID,
            'checkoutDate' => now()->subDays(10),
            'dueDate'      => now()->addDays(4),
            'status'       => 'Active',
        ], $attributes));
    }

    private function book(): Book
    {
        return Book::factory()->create(['subjectID' => BookSubject::factory()->create()->subjectID]);
    }

    private function overview(int $months = 3)
    {
        return $this->actingAs($this->librarian())->getJson("/api/librarian/reports/overview?months={$months}")->assertOk();
    }

    public function test_overdue_records_group_late_loans_by_how_late_they_are(): void
    {
        $subject = BookSubject::factory()->create();
        $book = Book::factory()->create(['subjectID' => $subject->subjectID]);
        $copies = BookCopy::factory()->count(4)->create(['bookID' => $book->bookID, 'status' => 'borrowed']);

        $this->loan($copies[0], ['dueDate' => now()->subDays(2)]);
        $this->loan($copies[1], ['dueDate' => now()->subDays(6)]);
        $this->loan($copies[2], ['dueDate' => now()->subDays(20)]);
        $this->loan($copies[3], ['dueDate' => now()->addDays(3)]); // not late yet

        $overdue = $this->overview()->json('overdue');

        $this->assertSame(3, $overdue['now']);
        $this->assertSame([1, 1, 0, 1], array_column($overdue['aging'], 'count'));
        $this->assertCount(3, $overdue['oldest']);
        $this->assertSame(20, $overdue['oldest'][0]['daysOverdue']);
        $this->assertSame(3, array_sum($overdue['byMonth']['counts']));
    }

    public function test_a_loan_returned_late_counts_as_late_but_one_returned_on_time_does_not(): void
    {
        $book = $this->book();
        $copies = BookCopy::factory()->count(2)->create(['bookID' => $book->bookID, 'status' => 'available']);

        $this->loan($copies[0], ['dueDate' => now()->subDays(5), 'returnDate' => now()->subDay(), 'status' => 'Returned']);
        $this->loan($copies[1], ['dueDate' => now()->subDays(5), 'returnDate' => now()->subDays(6), 'status' => 'Returned']);

        $overdue = $this->overview()->json('overdue');

        $this->assertSame(0, $overdue['now']);
        $this->assertSame(1, array_sum($overdue['byMonth']['counts']));
    }

    public function test_utilization_reports_copies_out_titles_nobody_borrowed_and_loans_per_copy(): void
    {
        $busy = BookSubject::factory()->create(['name' => 'Computing']);
        $quiet = BookSubject::factory()->create(['name' => 'Poetry']);
        $popular = Book::factory()->create(['subjectID' => $busy->subjectID]);
        $ignored = Book::factory()->create(['subjectID' => $quiet->subjectID]);

        $out = BookCopy::factory()->create(['bookID' => $popular->bookID, 'status' => 'borrowed']);
        BookCopy::factory()->create(['bookID' => $popular->bookID, 'status' => 'available']);
        BookCopy::factory()->create(['bookID' => $ignored->bookID, 'status' => 'available']);
        BookCopy::factory()->create(['bookID' => $ignored->bookID, 'status' => 'retired']); // not lendable

        $this->loan($out);

        $utilization = $this->overview()->json('utilization');

        $this->assertSame(1, $utilization['copiesOut']);
        $this->assertSame(3, $utilization['lendableCopies']);
        $this->assertEquals(33.3, $utilization['rate']);
        $this->assertSame(1, $utilization['neverBorrowedTitles']);
        $this->assertSame('Computing', $utilization['bySubject'][0]['name']);
        $this->assertEquals(0.5, $utilization['bySubject'][0]['perCopy']);
    }

    public function test_trends_compare_the_period_with_the_one_before_it(): void
    {
        $book = $this->book();
        $copies = BookCopy::factory()->count(3)->create(['bookID' => $book->bookID, 'status' => 'available']);

        // Two loans this period, one in the period before it.
        $this->loan($copies[0], ['checkoutDate' => now()->subDays(5), 'status' => 'Returned', 'returnDate' => now()->subDays(4)]);
        $this->loan($copies[1], ['checkoutDate' => now()->subDays(8), 'status' => 'Returned', 'returnDate' => now()->subDays(7)]);
        $this->loan($copies[2], ['checkoutDate' => now()->subMonths(4), 'status' => 'Returned', 'returnDate' => now()->subMonths(4)->addDay()]);

        $loans = $this->overview(3)->json('trends.loans');

        $this->assertSame(2, $loans['current']);
        $this->assertSame(1, $loans['previous']);
        $this->assertEquals(100.0, $loans['change']);
    }

    public function test_a_change_is_not_given_when_there_is_nothing_to_compare_with(): void
    {
        $trends = $this->overview()->json('trends');

        $this->assertNull($trends['loans']['change']);
        $this->assertNull($trends['visits']['change']);
        $this->assertNull($trends['late']['change']);
    }
}
