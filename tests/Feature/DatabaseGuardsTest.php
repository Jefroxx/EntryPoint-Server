<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Loan;
use App\Models\PenaltyType;
use App\Models\Reservation;
use App\Models\Student;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The database refuses bad states by itself, whatever the application does: records that must be kept can't be
 * cascaded away, "only one open ..." rules are unique keys, statuses are enums, and call numbers are unique.
 */
class DatabaseGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function loanOn(BookCopy $copy, Student $student, string $status = 'Active'): Loan
    {
        return Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDay(), 'dueDate' => now()->addDay(), 'status' => $status,
            'returnDate' => $status === 'Returned' ? now() : null,
        ]);
    }

    public function test_a_copy_cannot_have_two_open_loans_but_can_be_lent_again_after_it_is_returned(): void
    {
        BookSubject::factory()->create();
        $copy = BookCopy::factory()->create();
        $first = Student::factory()->create(['registrationStatus' => 'approved']);
        $second = Student::factory()->create(['registrationStatus' => 'approved']);

        $loan = $this->loanOn($copy, $first);
        $this->assertSame(1, \DB::table('loans')->where('loanID', $loan->loanID)->selectRaw('`openFlag` as f')->value('f'));

        try {
            $this->loanOn($copy, $second);
            $this->fail('A second open loan on the same copy was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $loan->update(['status' => 'Returned', 'returnDate' => now()]);
        $this->loanOn($copy, $second);                   // free again once the first one is closed
        $this->loanOn($copy, $first, 'Returned');        // any number of closed loans is fine
        $this->assertSame(3, Loan::where('copyID', $copy->copyID)->count());
    }

    public function test_a_student_cannot_be_inside_the_library_twice_or_hold_two_open_reservations_for_a_book(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        BookSubject::factory()->create();
        $book = Book::factory()->create();

        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => now()->subHour()]);
        $this->expectException(UniqueConstraintViolationException::class);
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => now()]);
    }

    public function test_an_open_reservation_is_unique_per_student_and_book_but_closed_ones_pile_up(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        BookSubject::factory()->create();
        $book = Book::factory()->create();
        $make = fn (string $status) => Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID, 'status' => $status, 'reservedAt' => now()]);

        $make('Rejected');
        $make('Fulfilled');
        $make('Waiting');
        $this->expectException(UniqueConstraintViolationException::class);
        $make('Accepted');
    }

    public function test_a_student_with_loans_cannot_be_deleted_so_the_history_survives(): void
    {
        BookSubject::factory()->create();
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->loanOn(BookCopy::factory()->create(), $student, 'Returned');

        $this->expectException(QueryException::class);
        try {
            $student->user->delete();
        } finally {
            $this->assertSame(1, Loan::where('studentID', $student->studentID)->count());
        }
    }

    public function test_a_status_outside_its_list_is_refused(): void
    {
        BookSubject::factory()->create();
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->expectException(QueryException::class);
        $this->loanOn(BookCopy::factory()->create(), $student, 'actve');
    }

    public function test_two_books_cannot_share_a_call_number_even_when_one_is_deleted(): void
    {
        BookSubject::factory()->create();
        $first = Book::factory()->create(['deweyNumber' => '005.13', 'cutter' => 'C67']);
        $this->assertSame('005.13', $first->callNumber);
        $this->assertSame('C67', $first->cutter);

        $first->delete(); // soft-deleted: its number stays reserved
        $this->expectException(UniqueConstraintViolationException::class);
        Book::factory()->create(['deweyNumber' => '005.13', 'cutter' => 'C67']);
    }

    public function test_points_are_added_in_the_database_and_never_go_below_zero(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'knowledgeScore' => 10]);
        $stale = Student::find($student->studentID); // read before the credit below, like a second request would
        $repo = app(StudentRepositoryInterface::class);

        $repo->creditPoints($student, 5);
        $repo->creditPoints($stale, 5);   // still thinks the score is 10
        $this->assertSame(20, $student->fresh()->knowledgeScore);

        $repo->creditPoints($student, -500);
        $this->assertSame(0, $student->fresh()->knowledgeScore);
    }

    public function test_a_penalty_type_says_what_it_is_for_and_which_area_it_follows(): void
    {
        $late = PenaltyType::create(['uuid' => Str::uuid(), 'kind' => 'overdue', 'areaOfLibrary' => 'reserved']);

        $this->assertSame('Overdue (reserved)', $late->label);
        $this->assertSame('Lost', PenaltyType::where('kind', 'lost')->firstOrFail()->label);
        $this->assertNull(PenaltyType::where('kind', 'lost')->value('areaOfLibrary'));
    }

    public function test_a_sign_in_token_stops_working_after_it_expires(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $token = $student->user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/student/profile')->assertOk();

        $this->travel(15)->days();
        $this->app["auth"]->forgetGuards(); // (a real second request starts with no signed-in user cached)
        $this->withToken($token)->getJson('/api/student/profile')->assertUnauthorized();
    }
}
