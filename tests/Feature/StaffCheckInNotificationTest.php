<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** When a student checks in, the library staff are told (the attendance diagrams in the paper show this). */
class StaffCheckInNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'librarian'): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    private function scan(User $by, Student $student)
    {
        return $this->actingAs($by)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => $student->barcodeValue]);
    }

    public function test_the_other_staff_are_told_when_a_student_checks_in_but_not_the_one_who_scanned(): void
    {
        $scanner = $this->staff();
        $colleague = $this->staff('assistant');
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'barcodeValue' => 'STI-TEST-' . Str::random(6)]);

        $this->scan($scanner, $student)->assertSuccessful()->assertJsonPath('action', 'check_in');

        $this->assertDatabaseHas('notifications', ['userID' => $colleague->userID, 'type' => 'student_checked_in']);
        $this->assertDatabaseMissing('notifications', ['userID' => $scanner->userID, 'type' => 'student_checked_in']);

        $message = \App\Models\SystemNotification::where('userID', $colleague->userID)->where('type', 'student_checked_in')->value('message');
        $this->assertStringContainsString($student->user->fullName, $message);
    }

    public function test_a_check_out_does_not_notify_the_staff(): void
    {
        $scanner = $this->staff();
        $colleague = $this->staff();
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'barcodeValue' => 'STI-TEST-' . Str::random(6)]);

        $this->scan($scanner, $student)->assertSuccessful();
        $this->travel(2)->minutes();
        app('auth')->forgetGuards();
        $this->scan($scanner, $student)->assertSuccessful()->assertJsonPath('action', 'check_out');

        $this->assertSame(1, \App\Models\SystemNotification::where('userID', $colleague->userID)->where('type', 'student_checked_in')->count());
    }
}
