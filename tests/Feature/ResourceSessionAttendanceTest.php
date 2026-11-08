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

/** A session needs a student who is in the library, and logging out of the library ends it. */
class ResourceSessionAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private Resource $computer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['attendance.double_scan_seconds' => 0]);

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $this->student = Student::factory()->create(['registrationStatus' => 'approved', 'memberIDNumber' => '02-2024-777']);
        $this->computer = Resource::factory()->create(['name' => 'Computer 15', 'resourceType' => 'computer']);
    }

    private function start(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/resource-usage-logs', [
            'resID' => $this->computer->resID, 'studentID' => $this->student->studentID,
        ]);
    }

    private function scanId(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => '02-2024-777']);
    }

    public function test_a_student_who_is_not_logged_in_cannot_start_a_session(): void
    {
        $this->start()->assertUnprocessable()->assertJsonValidationErrors('studentID');

        $this->assertSame('Available', $this->computer->fresh()->status);
    }

    public function test_a_visit_left_open_from_an_earlier_day_does_not_count_as_logged_in(): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'entryTime' => now()->subDay()]);

        $this->start()->assertUnprocessable();
    }

    public function test_a_logged_in_student_can_start_a_session(): void
    {
        $this->scanId()->assertCreated();   // check in

        $this->start()->assertCreated();
        $this->assertSame('In Use', $this->computer->fresh()->status);
    }

    public function test_logging_out_of_the_library_ends_the_running_session(): void
    {
        $this->scanId()->assertCreated();
        $this->start()->assertCreated();

        $this->scanId()   // check out
            ->assertOk()
            ->assertJsonPath('action', 'check_out')
            ->assertJsonPath('endedSession.name', 'Computer 15');

        $this->assertSame('Available', $this->computer->fresh()->status);
        $this->assertNotNull(ResourceUsageLog::where('studentID', $this->student->studentID)->value('endTime'));
    }

    public function test_a_session_the_librarian_already_ended_is_not_ended_twice(): void
    {
        $this->scanId()->assertCreated();
        $this->start()->assertCreated();

        $uuid = ResourceUsageLog::where('studentID', $this->student->studentID)->value('uuid');
        $this->actingAs($this->librarian)->postJson("/api/librarian/resource-usage-logs/{$uuid}/end")->assertOk();

        $this->scanId()->assertOk()->assertJsonPath('endedSession', null);
    }
}
