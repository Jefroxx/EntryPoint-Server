<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Resource;
use App\Models\ResourceUsageLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ending the scan station logs everyone still in the library out. */
class EndStationSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function enter(Student $student, $at = null): AttendanceLog
    {
        return AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => $at ?? now()->subHour()]);
    }

    public function test_everyone_inside_is_logged_out_with_their_sessions(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $gone = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->enter($ana);
        $this->enter($ben);
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $gone->studentID, 'entryTime' => now()->subHours(3), 'exitTime' => now()->subHour()]);   // already out
        AttendanceLog::create(['uuid' => Str::uuid(), 'visitorName' => 'Maria Santos', 'visitorPurpose' => 'Research', 'entryTime' => now()->subHour()]);

        $computer = Resource::factory()->create(['status' => 'In Use']);
        ResourceUsageLog::create(['uuid' => Str::uuid(), 'resID' => $computer->resID, 'studentID' => $ana->studentID, 'staffLibrarianID' => $this->librarian->librarian->librarianID, 'startTime' => now()->subMinutes(30)]);

        // The confirmation reads these numbers first.
        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats')
            ->assertOk()->assertJsonPath('inside.students', 2)->assertJsonPath('inside.visitors', 1);

        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/close-all')
            ->assertOk()
            ->assertJsonPath('students', 2)
            ->assertJsonPath('visitors', 1)
            ->assertJsonPath('sessionsEnded', 1);

        $this->assertSame(0, AttendanceLog::whereNull('exitTime')->count());
        $this->assertSame('Available', $computer->fresh()->status);
        $this->assertNotNull(ResourceUsageLog::where('studentID', $ana->studentID)->value('endTime'));

        // Each student who was inside is told they were checked out (and the one already out is not).
        foreach ([$ana, $ben] as $student) {
            $this->assertDatabaseHas('notifications', ['userID' => $student->studentID, 'type' => 'attendance_check_out']);
        }
        $this->assertDatabaseMissing('notifications', ['userID' => $gone->studentID, 'type' => 'attendance_check_out']);

        // Running it again finds nobody.
        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/close-all')
            ->assertOk()->assertJsonPath('students', 0)->assertJsonPath('visitors', 0);
    }

    public function test_a_visit_left_from_an_earlier_day_closes_at_that_days_closing_time(): void
    {
        config(['attendance.auto_close' => 'closing_time', 'attendance.closing_time' => '18:00']);
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $log = $this->enter($student, now()->subDays(2)->setTime(9, 0));

        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/close-all')->assertOk()->assertJsonPath('students', 1);

        $this->assertSame('18:00', $log->fresh()->exitTime->format('H:i'));
        $this->assertTrue($log->fresh()->exitTime->isSameDay(now()->subDays(2)));
    }

    public function test_students_cannot_end_the_session(): void
    {
        $student = User::factory()->create(['userType' => 'student']);

        $this->actingAs($student)->postJson('/api/librarian/attendance-logs/close-all')->assertForbidden();
    }
}
