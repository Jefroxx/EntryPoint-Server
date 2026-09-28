<?php

namespace Tests\Feature;

use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTodayTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    public function test_it_returns_todays_numbers_and_the_attention_queues(): void
    {
        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/today')
            ->assertOk()
            ->assertJsonStructure([
                'inLibrary', 'visitsToday', 'checkedOut', 'returned', 'dueToday', 'overdue',
                'attention' => ['registrations', 'bookRequests', 'reservations', 'selfReturns', 'redemptions', 'unpaidFines', 'unpaidTotal'],
            ]);
    }

    public function test_only_confirmed_pending_registrations_count_as_ready_for_review(): void
    {
        Student::factory()->create(); // pending, email confirmed (factory default)

        $unconfirmed = Student::factory()->create();
        $unconfirmed->user->update(['emailVerifiedAt' => null]);

        Student::factory()->create(['registrationStatus' => 'approved']);

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/today')
            ->assertOk()
            ->assertJsonPath('attention.registrations', 1);
    }

    public function test_only_pending_book_requests_are_counted(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        BookSuggestion::factory()->count(2)->create(['studentID' => $student->studentID, 'status' => 'Pending']);
        BookSuggestion::factory()->create(['studentID' => $student->studentID, 'status' => 'Approved', 'progressStep' => 'Approved']);

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/today')
            ->assertJsonPath('attention.bookRequests', 2);
    }

    public function test_students_cannot_read_it(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->actingAs($student->user)
            ->getJson('/api/librarian/dashboard/today')
            ->assertForbidden();
    }
}
