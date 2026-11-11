<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The attendance log says which staff account scanned each visit in and out. */
class AttendanceRecordedByTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private User $assistant;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['attendance.double_scan_seconds' => 0]);

        $this->librarian = $this->staff('librarian', ['firstName' => 'Maria', 'middleInitial' => null, 'lastName' => 'Santos']);
        $this->assistant = $this->staff('assistant', ['firstName' => 'Paolo', 'middleInitial' => null, 'lastName' => 'Lim']);
        $this->student = Student::factory()->create(['registrationStatus' => 'approved', 'memberIDNumber' => '02-2024-777']);
    }

    private function staff(string $role, array $user): User
    {
        $account = User::factory()->create(['userType' => 'librarian'] + $user);
        Librarian::create(['librarianID' => $account->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $account;
    }

    private function scanAs(User $who)
    {
        return $this->actingAs($who)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => '02-2024-777']);
    }

    public function test_the_account_that_scans_a_student_in_and_out_is_recorded_and_shown(): void
    {
        $in = $this->scanAs($this->librarian)->assertCreated()
            ->assertJsonPath('log.checkedInByStaff', ['name' => 'Maria Santos', 'role' => 'librarian'])
            ->assertJsonPath('log.checkedOutByStaff', null);

        $log = AttendanceLog::findOrFail($in->json('log.logID'));
        $this->assertSame($this->librarian->userID, $log->checkedInBy);

        // A different account scans the student out: both are kept, apart.
        $this->scanAs($this->assistant)->assertOk()
            ->assertJsonPath('log.checkedInByStaff.name', 'Maria Santos')
            ->assertJsonPath('log.checkedOutByStaff', ['name' => 'Paolo Lim', 'role' => 'assistant']);

        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs')->assertOk()
            ->assertJsonPath('data.0.checkedInByStaff.name', 'Maria Santos')
            ->assertJsonPath('data.0.checkedOutByStaff.name', 'Paolo Lim')
            ->assertJsonPath('data.0.checkedOutByStaff.role', 'assistant');
    }

    public function test_the_universal_scanner_records_the_account_too(): void
    {
        $this->actingAs($this->assistant)->postJson('/api/librarian/scan', ['code' => '02-2024-777'])
            ->assertOk()->assertJsonPath('type', 'attendance')
            ->assertJsonPath('log.checkedInByStaff.name', 'Paolo Lim');
    }

    public function test_a_walk_in_visitor_records_who_logged_them_in_and_out(): void
    {
        $visit = $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/visitor', ['name' => 'Ana Reyes', 'purpose' => 'Research'])
            ->assertCreated()->assertJsonPath('log.checkedInByStaff.name', 'Maria Santos');

        $this->actingAs($this->assistant)->postJson('/api/librarian/attendance-logs/'.$visit->json('log.uuid').'/visitor-checkout')
            ->assertOk()->assertJsonPath('log.checkedOutByStaff.name', 'Paolo Lim');
    }

    public function test_ending_the_day_credits_todays_visits_to_the_person_but_old_ones_to_nobody(): void
    {
        $today = AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'entryTime' => now()->subHour()]);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $old = AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $other->studentID, 'entryTime' => now()->subDays(2)->setTime(9, 0)]);

        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/close-all')->assertOk();

        $this->assertSame($this->librarian->userID, $today->fresh()->checkedOutBy);
        // A visit left open from an earlier day is tidied up by the system, as the nightly clean-up does: nobody scanned it.
        $this->assertNotNull($old->fresh()->exitTime);
        $this->assertNull($old->fresh()->checkedOutBy);
    }

    public function test_visits_from_before_this_was_kept_simply_show_nobody(): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'entryTime' => now()->subDays(3), 'exitTime' => now()->subDays(3)->addHour()]);

        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs')->assertOk()
            ->assertJsonPath('data.0.checkedInByStaff', null)
            ->assertJsonPath('data.0.checkedOutByStaff', null);
    }

    public function test_a_student_is_not_told_which_staff_member_scanned_them(): void
    {
        $this->scanAs($this->librarian)->assertCreated();

        $visits = $this->actingAs($this->student->user)->getJson('/api/student/attendance')->assertOk()->json('visits');

        $this->assertNotEmpty($visits);
        foreach ($visits as $visit) {
            $this->assertArrayNotHasKey('checkedInByStaff', $visit);
            $this->assertArrayNotHasKey('checkedOutByStaff', $visit);
            $this->assertArrayNotHasKey('checkedInByUser', $visit);
        }
    }

    public function test_deleting_the_staff_account_keeps_the_visit(): void
    {
        $in = $this->scanAs($this->assistant)->assertCreated();

        $this->assistant->delete();

        $log = AttendanceLog::findOrFail($in->json('log.logID'));
        $this->assertNull($log->checkedInBy);
        $this->assertNotNull($log->entryTime);
    }
}
