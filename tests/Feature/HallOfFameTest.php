<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HallOfFameTest extends TestCase
{
    use RefreshDatabase;

    private function visit(Student $student, string $entry, ?string $exit = null): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => $entry, 'exitTime' => $exit]);
    }

    private function category(array $board, string $key): array
    {
        return collect($board['categories'])->firstWhere('key', $key);
    }

    public function test_most_active_counts_days_not_check_ins_and_shows_first_name_and_initial(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(12, 0));

        $busy = Student::factory()->create(['registrationStatus' => 'approved']);
        $busy->user->update(['firstName' => 'Maria', 'lastName' => 'Santos']);
        $steady = Student::factory()->create(['registrationStatus' => 'approved']);

        // Maria scans in three times on one day; her classmate comes on two different days.
        foreach (['08:00', '10:00', '13:00'] as $time) {
            $this->visit($busy, now()->subDay()->format('Y-m-d') . " {$time}");
        }
        $this->visit($steady, now()->subDays(1)->format('Y-m-d 09:00'));
        $this->visit($steady, now()->subDays(2)->format('Y-m-d 09:00'));

        $board = $this->actingAs($steady->user)->getJson('/api/student/hall-of-fame')->assertOk()->json();
        $leaders = $this->category($board, 'most-active')['leaders'];

        $this->assertSame([2.0, 1.0], array_map('floatval', array_column($leaders, 'value')));
        $this->assertTrue($leaders[0]['isYou']);
        $this->assertSame('Maria S.', $leaders[1]['name']);
        $this->assertArrayNotHasKey('studentID', $leaders[0]);
        $this->assertEquals(2, $this->category($board, 'most-active')['yourValue']);
    }

    public function test_students_awaiting_approval_are_not_ranked(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(12, 0));

        $pending = Student::factory()->create(['registrationStatus' => 'pending', 'visitStreak' => 30]);
        $viewer = Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 2]);

        $board = $this->actingAs($viewer->user)->getJson('/api/student/hall-of-fame')->assertOk()->json();
        $leaders = $this->category($board, 'longest-streak')['leaders'];

        $this->assertCount(1, $leaders);
        $this->assertTrue($leaders[0]['isYou']);
        $this->assertNotContains($pending->user->firstName . ' ' . mb_substr($pending->user->lastName, 0, 1) . '.', array_column($leaders, 'name'));
    }

    public function test_ties_share_a_place(): void
    {
        $a = Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 5]);
        Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 5]);
        Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 3]);

        $board = $this->actingAs($a->user)->getJson('/api/student/hall-of-fame')->assertOk()->json();

        $this->assertSame([1, 1, 3], array_column($this->category($board, 'longest-streak')['leaders'], 'rank'));
    }

    public function test_librarians_see_full_names_and_id_numbers(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 9, 'studentIDNumber' => '02-2024-001']);
        $student->user->update(['firstName' => 'Maria', 'lastName' => 'Santos']);

        $librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $board = $this->actingAs($librarian)->getJson('/api/librarian/dashboard/hall-of-fame')->assertOk()->json();
        $leader = $this->category($board, 'longest-streak')['leaders'][0];

        $this->assertSame('Maria Santos', $leader['name']);
        $this->assertSame('02-2024-001', $leader['studentIDNumber']);
        $this->assertArrayNotHasKey('yourValue', $this->category($board, 'longest-streak'));
    }

    public function test_students_never_get_id_numbers_or_the_staff_board(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'visitStreak' => 9]);

        $board = $this->actingAs($student->user)->getJson('/api/student/hall-of-fame')->assertOk()->json();
        $this->assertArrayNotHasKey('studentIDNumber', $this->category($board, 'longest-streak')['leaders'][0]);

        $this->getJson('/api/librarian/dashboard/hall-of-fame')->assertForbidden();
    }

    public function test_last_months_visits_do_not_count(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(12, 0));

        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->visit($student, now()->subMonth()->format('Y-m-d 09:00'));

        $board = $this->actingAs($student->user)->getJson('/api/student/hall-of-fame')->assertOk()->json();

        $this->assertSame([], $this->category($board, 'most-active')['leaders']);
    }
}
