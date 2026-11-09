<?php

namespace Tests\Feature;

use App\Mail\VerifyStudentEmail;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Student assistants are picked from the registered students and sign in with a generated username; an assistant from
 * outside the school is made like a librarian (an email to confirm).
 */
class StudentAssistantAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->admin->userID, 'uuid' => Str::uuid(), 'role' => 'admin']);
    }

    private function pick(Student $student): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/staff', [
            'role' => 'assistant', 'assistantSource' => 'student', 'studentUuid' => $student->uuid,
        ]);
    }

    public function test_a_registered_student_becomes_an_assistant_with_a_username_and_no_email(): void
    {
        $student = Student::factory()->approved()->create();

        $response = $this->pick($student)->assertCreated()
            ->assertJsonPath('account.role', 'assistant')
            ->assertJsonPath('account.name', $student->user->fullName)
            ->assertJsonPath('account.email', null)
            ->assertJsonPath('account.emailConfirmed', null)
            ->assertJsonPath('account.linkedStudent', true)
            ->assertJsonPath('verificationSent', false);

        $username = $response->json('username');
        $this->assertMatchesRegularExpression('/^sa\.[a-z0-9]+\d{3}$/', $username);
        Mail::assertNothingSent();

        // Signs in on the librarian side with the username, with no email to confirm.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/librarian/login', ['email' => $username, 'password' => $response->json('temporaryPassword')])
            ->assertOk()
            ->assertJsonPath('staff.role', 'assistant');

        // The student's own account is untouched.
        $this->assertSame('student', $student->user->fresh()->userType);
        $this->assertDatabaseHas('librarians', ['linkedStudentID' => $student->studentID, 'role' => 'assistant']);
    }

    public function test_only_a_student_who_is_registered_in_the_system_can_be_picked(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/staff', ['role' => 'assistant', 'assistantSource' => 'student'])
            ->assertJsonValidationErrors('studentUuid');

        $this->actingAs($this->admin)->postJson('/api/admin/staff', [
            'role' => 'assistant', 'assistantSource' => 'student', 'studentUuid' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('studentUuid');

        // Waiting for approval, or a faculty member, doesn't count as a registered student.
        $this->pick(Student::factory()->create(['registrationStatus' => 'pending']))->assertJsonValidationErrors('studentUuid');
        $this->pick(Student::factory()->faculty()->approved()->create())->assertJsonValidationErrors('studentUuid');

        $this->assertSame(1, Librarian::count());
    }

    public function test_a_student_can_only_be_made_an_assistant_once(): void
    {
        $student = Student::factory()->approved()->create();

        $this->pick($student)->assertCreated();
        $this->pick($student)->assertStatus(422)->assertJsonValidationErrors('studentUuid');
    }

    public function test_the_picker_lists_only_registered_students_who_are_not_assistants_yet(): void
    {
        $free = Student::factory()->approved()->create();
        $taken = Student::factory()->approved()->create();
        Student::factory()->create(['registrationStatus' => 'pending']);
        Student::factory()->faculty()->approved()->create();
        $this->pick($taken)->assertCreated();

        $uuids = collect($this->actingAs($this->admin)->getJson('/api/admin/staff/assistant-candidates')->assertOk()->json('students'))->pluck('uuid');

        $this->assertSame([(string) $free->uuid], $uuids->all());
    }

    public function test_an_outside_assistant_is_made_like_a_librarian_with_an_email_to_confirm(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/staff', [
            'role' => 'assistant', 'assistantSource' => 'outside',
            'firstName' => 'Mara', 'lastName' => 'Cruz', 'email' => 'mara@example.com',
        ])->assertCreated()->assertJsonPath('account.emailConfirmed', false)->assertJsonPath('account.linkedStudent', false);

        Mail::assertSent(VerifyStudentEmail::class, fn ($mail) => $mail->hasTo('mara@example.com'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/librarian/login', ['email' => 'mara@example.com', 'password' => $response->json('temporaryPassword')])
            ->assertStatus(422)->assertJsonValidationErrors('verification');
    }

    public function test_an_outside_assistant_needs_a_name_and_an_email_and_cannot_pick_a_student(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/staff', ['role' => 'assistant', 'assistantSource' => 'outside'])
            ->assertJsonValidationErrors(['firstName', 'lastName', 'email']);

        $this->actingAs($this->admin)->postJson('/api/admin/staff', [
            'role' => 'librarian', 'firstName' => 'A', 'lastName' => 'B', 'email' => 'ab@example.com',
            'studentUuid' => Student::factory()->approved()->create()->uuid,
        ])->assertJsonValidationErrors('studentUuid');
    }
}
