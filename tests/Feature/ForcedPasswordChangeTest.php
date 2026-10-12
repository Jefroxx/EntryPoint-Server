<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An account the administrator made (or reset) starts on a temporary password: until its owner picks their own, the
 * account can change its password and sign out, and nothing else.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->admin->userID, 'uuid' => Str::uuid(), 'role' => 'admin']);
    }

    /** Signs in the way the app does and returns the bearer token and the user the server sent back. */
    private function signIn(string $path, string $email, string $password): array
    {
        $this->app['auth']->forgetGuards();
        $response = $this->postJson($path, ['email' => $email, 'password' => $password])->assertOk();

        return [$response->json('access_token'), $response->json('user')];
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_new_staff_account_must_set_its_own_password_before_doing_anything_else(): void
    {
        $created = $this->actingAs($this->admin)->postJson('/api/admin/staff', [
            'firstName' => 'Paolo', 'lastName' => 'Lim', 'email' => 'paolo@stidavao.edu.ph', 'role' => 'assistant',
        ])->assertCreated();
        $temporary = $created->json('temporaryPassword');

        [$token, $user] = $this->signIn('/api/librarian/login', 'paolo@stidavao.edu.ph', $temporary);
        $this->assertTrue($user['mustChangePassword']);

        // Everything but changing the password (and asking who they are) is refused, with a code the screens act on.
        $this->as($token)->getJson('/api/librarian/loans')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->getJson('/api/librarian/dashboard/today')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->getJson('/api/librarian/me')->assertOk();

        // The temporary password cannot be "changed" to itself.
        $this->as($token)->putJson('/api/librarian/settings/password', [
            'currentPassword' => $temporary, 'newPassword' => $temporary, 'newPassword_confirmation' => $temporary,
        ])->assertStatus(422)->assertJsonValidationErrors('newPassword');

        $this->as($token)->putJson('/api/librarian/settings/password', [
            'currentPassword' => $temporary, 'newPassword' => 'MyOwnPassword9', 'newPassword_confirmation' => 'MyOwnPassword9',
        ])->assertOk();

        $this->as($token)->getJson('/api/librarian/loans')->assertOk();
        $this->assertFalse(User::where('email', 'paolo@stidavao.edu.ph')->first()->mustChangePassword);

        [, $after] = $this->signIn('/api/librarian/login', 'paolo@stidavao.edu.ph', 'MyOwnPassword9');
        $this->assertFalse($after['mustChangePassword']);
    }

    public function test_a_new_faculty_member_is_held_to_the_same_rule_on_the_student_side(): void
    {
        $created = $this->actingAs($this->admin)->postJson('/api/admin/members', [
            'firstName' => 'Roberto', 'lastName' => 'Alcantara', 'email' => 'roberto@davao.sti.edu.ph',
            'memberType' => 'faculty', 'employeeIDNumber' => 'EMP-1', 'department' => 'IT',
        ])->assertCreated();
        $temporary = $created->json('temporaryPassword');

        [$token, $user] = $this->signIn('/api/login', 'roberto@davao.sti.edu.ph', $temporary);
        $this->assertTrue($user['mustChangePassword']);

        $this->as($token)->getJson('/api/student/loans')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->getJson('/api/student/catalog')->assertForbidden();

        $this->as($token)->putJson('/api/student/password', [
            'currentPassword' => $temporary, 'newPassword' => 'FacultyPass7', 'newPassword_confirmation' => 'FacultyPass7',
        ])->assertOk();

        $this->as($token)->getJson('/api/student/loans')->assertOk();
    }

    public function test_a_password_reset_by_the_admin_asks_for_a_new_one_again(): void
    {
        $librarian = User::factory()->create(['userType' => 'librarian', 'email' => 'lib@stidavao.edu.ph']);
        Librarian::create(['librarianID' => $librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $this->assertFalse($librarian->fresh()->mustChangePassword);

        $temporary = $this->actingAs($this->admin)->postJson("/api/admin/staff/{$librarian->librarian->uuid}/reset-password")->assertOk()->json('temporaryPassword');

        $this->assertTrue($librarian->fresh()->mustChangePassword);
        [, $user] = $this->signIn('/api/librarian/login', 'lib@stidavao.edu.ph', $temporary);
        $this->assertTrue($user['mustChangePassword']);
    }

    public function test_ordinary_accounts_are_not_affected(): void
    {
        $student = Student::factory()->approved()->create();

        $this->actingAs($student->user)->getJson('/api/student/loans')->assertOk();
        $this->assertFalse($student->user->fresh()->mustChangePassword);

        // Changing a password the usual way never needed the flag.
        $this->actingAs($student->user)->putJson('/api/student/password', [
            'currentPassword' => 'password123', 'newPassword' => 'AnotherPass8', 'newPassword_confirmation' => 'AnotherPass8',
        ])->assertOk();
    }
}
