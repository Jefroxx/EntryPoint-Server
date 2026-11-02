<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Advisor feedback (Nov 2026): the cart can't outgrow the student's free slots; walk-in visitors can be logged. */
class CartSlotsAndVisitorsTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    public function test_the_cart_holds_no_more_books_than_the_student_has_free_slots(): void
    {
        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $books = Book::factory()->count(3)->create();

        // One slot is already used by a loan, so only 2 are free.
        \DB::table('loans')->insert($this->loanRow($student));

        $this->actingAs($student->user)->postJson('/api/student/cart', ['bookID' => $books[0]->bookID])->assertCreated();
        $this->actingAs($student->user)->postJson('/api/student/cart', ['bookID' => $books[1]->bookID])->assertCreated();
        $this->actingAs($student->user)->postJson('/api/student/cart', ['bookID' => $books[2]->bookID])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bookID');
    }

    public function test_a_librarian_can_log_a_visitor_and_check_them_out(): void
    {
        $librarian = $this->librarian();

        $uuid = $this->actingAs($librarian)
            ->postJson('/api/librarian/attendance-logs/visitor', ['name' => 'Maria Santos', 'purpose' => 'Research'])
            ->assertCreated()
            ->json('log.uuid');

        $this->assertDatabaseHas('attendance_logs', ['visitorName' => 'Maria Santos', 'studentID' => null, 'exitTime' => null]);

        $this->actingAs($librarian)->getJson('/api/librarian/attendance-logs/stats')->assertJsonPath('currentlyInLibrary', 1);

        $this->actingAs($librarian)->postJson("/api/librarian/attendance-logs/{$uuid}/visitor-checkout")->assertOk();
        $this->assertNotNull(AttendanceLog::where('uuid', $uuid)->value('exitTime'));

        $this->actingAs($librarian)->postJson("/api/librarian/attendance-logs/{$uuid}/visitor-checkout")->assertStatus(422);
    }

    public function test_a_visitor_needs_a_name_and_a_purpose(): void
    {
        $this->actingAs($this->librarian())
            ->postJson('/api/librarian/attendance-logs/visitor', ['name' => '', 'purpose' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'purpose']);
    }

    private function loanRow(Student $student): array
    {
        $copy = BookCopy::factory()->create(['bookID' => Book::factory()->create()->bookID]);

        return [
            'uuid'         => Str::uuid(),
            'studentID'    => $student->studentID,
            'copyID'       => $copy->copyID,
            'checkoutDate' => now(),
            'dueDate'      => now()->addDays(3),
            'status'       => 'Active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }
}
