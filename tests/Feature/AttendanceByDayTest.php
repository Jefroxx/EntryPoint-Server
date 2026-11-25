<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The attendance log can be browsed one day at a time. */
class AttendanceByDayTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function visit(Student $student, string $in, ?string $out): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => $in, 'exitTime' => $out]);
    }

    public function test_the_log_and_its_numbers_can_be_read_for_one_day(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $day = now()->subDays(3)->toDateString();

        // Ana came twice that day, Ben once; Ben was also in on another day.
        $this->visit($ana, "{$day} 08:00:00", "{$day} 09:00:00");
        $this->visit($ana, "{$day} 13:00:00", "{$day} 13:30:00");
        $this->visit($ben, "{$day} 10:00:00", "{$day} 11:00:00");
        $this->visit($ben, now()->toDateString().' 08:00:00', null);
        AttendanceLog::create(['uuid' => Str::uuid(), 'visitorName' => 'Maria Santos', 'visitorPurpose' => 'Research', 'entryTime' => "{$day} 14:00:00"]);

        $stats = $this->actingAs($this->librarian)->getJson("/api/librarian/attendance-logs/stats?date={$day}")->assertOk()->json();

        $this->assertSame($day, $stats['date']);
        $this->assertSame(4, $stats['day']['visits']);
        $this->assertSame(2, $stats['day']['students']);       // Ana and Ben, Ana counted once
        $this->assertSame(1, $stats['day']['visitors']);
        $this->assertEquals(50.0, $stats['day']['averageMinutes']);   // (60 + 30 + 60) / 3
        // The live number ignores the chosen day, and counts today's visits only: Ben (today) is in. The visitor left open
        // three days ago is not someone inside now, whatever the nightly clean-up has or hasn't got to yet.
        $this->assertSame(1, $stats['currentlyInLibrary']);

        $this->actingAs($this->librarian)->getJson("/api/librarian/attendance-logs?date={$day}")
            ->assertOk()->assertJsonPath('total', 4);
        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs?date='.now()->toDateString())
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_the_stats_default_to_today_and_reject_a_bad_date(): void
    {
        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats')
            ->assertOk()->assertJsonPath('date', today()->toDateString())->assertJsonPath('day.visits', 0);

        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats?date=yesterday')->assertUnprocessable();
    }

    public function test_the_average_is_empty_until_a_visit_has_ended(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->visit($ana, now()->toDateString().' 08:00:00', null);

        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats')
            ->assertOk()->assertJsonPath('day.averageMinutes', null);
    }

    public function test_the_log_can_be_narrowed_by_status_and_by_who_and_flipped(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $member = Student::factory()->faculty()->create(['registrationStatus' => 'approved']);
        $day = now()->toDateString();
        $this->visit($student, "{$day} 08:00:00", "{$day} 09:00:00");
        $this->visit($member, "{$day} 10:00:00", null);
        AttendanceLog::create(['uuid' => Str::uuid(), 'visitorName' => 'Maria Santos', 'visitorPurpose' => 'Research', 'entryTime' => "{$day} 11:00:00"]);

        $this->actingAs($this->librarian);
        $this->getJson("/api/librarian/attendance-logs?date={$day}&status=in")->assertJsonPath('total', 2);
        $this->getJson("/api/librarian/attendance-logs?date={$day}&status=out")->assertJsonPath('total', 1);
        $this->getJson("/api/librarian/attendance-logs?date={$day}&who=students")->assertJsonPath('total', 1);
        $this->getJson("/api/librarian/attendance-logs?date={$day}&who=members")->assertJsonPath('total', 1);
        $this->getJson("/api/librarian/attendance-logs?date={$day}&who=visitors")->assertJsonPath('total', 1);

        // Newest first unless asked, then the earliest visit leads.
        $this->getJson("/api/librarian/attendance-logs?date={$day}")->assertJsonPath('data.0.visitorName', 'Maria Santos');
        $this->getJson("/api/librarian/attendance-logs?date={$day}&sort=oldest")->assertJsonPath('data.0.studentID', $student->studentID);
    }

    public function test_the_station_can_tell_todays_visits_from_ones_left_open_on_earlier_days(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->visit($ana, now()->toDateString().' 08:00:00', null);
        $this->visit($ben, now()->subDays(3)->toDateString().' 08:00:00', null);

        $inside = $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats')->assertOk()->json('inside');

        // Both are open, and "End session" closes both, but only one of them is someone in the library today.
        $this->assertSame(2, $inside['students']);
        $this->assertSame(1, $inside['earlier']['students']);
        $this->assertSame(0, $inside['earlier']['visitors']);
    }

    public function test_a_faculty_member_scanned_in_shows_their_department(): void
    {
        $member = Student::factory()->faculty()->create(['registrationStatus' => 'approved', 'department' => 'College of Nursing', 'barcodeValue' => 'FAC-0001']);

        $this->actingAs($this->librarian)->postJson('/api/librarian/scan', ['code' => 'FAC-0001'])
            ->assertSuccessful()->assertJsonPath('student.program', 'College of Nursing');
    }
}
