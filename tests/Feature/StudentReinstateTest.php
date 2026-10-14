<?php

namespace Tests\Feature;

use App\Mail\StudentApproved;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Students page is split by status (approved, pending, rejected), and a rejected student can be reinstated,
 * which is approving them again.
 */
class StudentReinstateTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    public function test_the_list_can_be_filtered_to_each_status(): void
    {
        Student::factory()->count(2)->approved()->create();
        Student::factory()->create(); // pending
        Student::factory()->count(3)->rejected()->create();

        foreach (['approved' => 2, 'pending' => 1, 'rejected' => 3] as $status => $count) {
            $this->actingAs($this->librarian)->getJson("/api/librarian/students?status={$status}")
                ->assertOk()
                ->assertJsonPath('total', $count)
                ->assertJsonMissing(['registrationStatus' => $status === 'approved' ? 'rejected' : 'approved']);
        }
    }

    public function test_a_rejected_student_can_be_reinstated(): void
    {
        $student = Student::factory()->rejected()->create();

        $this->actingAs($this->librarian)->postJson("/api/librarian/students/{$student->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('student.registrationStatus', 'approved');

        $student->refresh();
        $this->assertSame('approved', $student->registrationStatus);
        $this->assertNotNull($student->barcodeValue);
        Mail::assertSent(StudentApproved::class);
        $this->assertDatabaseHas('notifications', ['userID' => $student->studentID, 'type' => 'registration_approved']);

        // Now approved, so they are in the approved list and out of the rejected one.
        $this->getJson('/api/librarian/students?status=rejected')->assertJsonPath('total', 0);
        $this->getJson('/api/librarian/students?status=approved')->assertJsonPath('total', 1);
    }

    public function test_a_student_who_never_confirmed_their_email_cannot_be_reinstated(): void
    {
        $student = Student::factory()->rejected()->create();
        $student->user->update(['emailVerifiedAt' => null]);

        $this->actingAs($this->librarian)->postJson("/api/librarian/students/{$student->uuid}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student');

        $this->assertSame('rejected', $student->fresh()->registrationStatus);
    }

    public function test_an_approved_student_cannot_be_approved_again(): void
    {
        $student = Student::factory()->approved()->create();

        $this->actingAs($this->librarian)->postJson("/api/librarian/students/{$student->uuid}/approve")->assertUnprocessable();
    }
}
