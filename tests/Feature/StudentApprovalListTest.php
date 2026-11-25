<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Students page's lists: who is waiting, in what order, and why a rejection happened. */
class StudentApprovalListTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function pending(bool $confirmed = true): Student
    {
        $student = Student::factory()->create(['registrationStatus' => 'pending']);
        $student->user->update(['emailVerifiedAt' => $confirmed ? now() : null]);

        return $student;
    }

    public function test_a_registration_that_has_not_confirmed_its_email_is_not_in_the_pending_list_or_counts(): void
    {
        $waiting = $this->pending();
        $this->pending(confirmed: false);

        $list = $this->actingAs($this->librarian)->getJson('/api/librarian/students?status=pending&memberType=student')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', (string) $waiting->uuid);

        $stats = $this->getJson('/api/librarian/students/stats?memberType=student')->assertOk();
        $stats->assertJsonPath('pending', 1)->assertJsonPath('total', 1);
    }

    public function test_the_pending_list_starts_with_the_oldest_and_can_be_flipped(): void
    {
        $first = $this->pending();
        $second = $this->pending();

        $this->actingAs($this->librarian)->getJson('/api/librarian/students?status=pending')
            ->assertJsonPath('data.0.uuid', (string) $first->uuid);
        $this->getJson('/api/librarian/students?status=pending&sort=newest')
            ->assertJsonPath('data.0.uuid', (string) $second->uuid);
        // Elsewhere the newest comes first, unless asked otherwise.
        $a = Student::factory()->create(['registrationStatus' => 'approved']);
        $b = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->getJson('/api/librarian/students?status=approved')->assertJsonPath('data.0.uuid', (string) $b->uuid);
        $this->getJson('/api/librarian/students?status=approved&sort=oldest')->assertJsonPath('data.0.uuid', (string) $a->uuid);
    }

    public function test_the_stats_list_every_program_for_the_filter(): void
    {
        Student::factory()->count(15)->create(['registrationStatus' => 'approved', 'academicProgram' => 'BS Nursing']);
        Student::factory()->create(['registrationStatus' => 'approved', 'academicProgram' => 'BS Biology']);

        $this->actingAs($this->librarian)->getJson('/api/librarian/students/stats?memberType=student')
            ->assertOk()->assertJsonPath('programs', ['BS Biology', 'BS Nursing']);
    }

    public function test_a_rejection_needs_a_reason_and_the_student_is_told_it(): void
    {
        $student = $this->pending();

        $this->actingAs($this->librarian)->postJson("/api/librarian/students/{$student->uuid}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('pending', $student->fresh()->registrationStatus);

        $this->postJson("/api/librarian/students/{$student->uuid}/reject", ['reason' => 'The ID number does not match your school ID.'])->assertOk();

        $this->assertSame('rejected', $student->fresh()->registrationStatus);
        $this->assertSame('The ID number does not match your school ID.', $student->fresh()->rejectionReason);
        $this->assertTrue(SystemNotification::where('userID', $student->studentID)->where('type', 'registration_rejected')
            ->where('message', 'like', '%does not match your school ID%')->exists());

        // Reinstating clears the old reason.
        $this->postJson("/api/librarian/students/{$student->uuid}/approve")->assertOk();
        $this->assertNull($student->fresh()->rejectionReason);
    }

    public function test_a_member_whose_data_was_removed_is_not_listed(): void
    {
        Student::factory()->faculty()->create(['registrationStatus' => 'approved']);
        Student::factory()->faculty()->create(['registrationStatus' => 'approved', 'memberIDNumber' => 'REMOVED-abc123']);

        $this->actingAs($this->librarian)->getJson('/api/librarian/students?memberType=members&status=approved')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_faculty_and_staff_list_counts_by_type_and_filters_by_department(): void
    {
        Student::factory()->faculty()->count(2)->create(['registrationStatus' => 'approved', 'department' => 'College of Nursing']);
        Student::factory()->faculty()->create(['registrationStatus' => 'approved', 'department' => 'College of Law', 'memberType' => 'staff']);

        $stats = $this->actingAs($this->librarian)->getJson('/api/librarian/students/stats?memberType=members')->assertOk();
        $stats->assertJsonPath('faculty', 2)->assertJsonPath('staff', 1)->assertJsonPath('programs', ['College of Law', 'College of Nursing']);

        $this->getJson('/api/librarian/students?memberType=members&program=College of Law')->assertJsonCount(1, 'data');
        $this->getJson('/api/librarian/students?memberType=faculty')->assertJsonCount(2, 'data');
    }
}
