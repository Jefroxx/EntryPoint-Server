<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The administrator's side: staff accounts, faculty and staff members, role permissions and the audit log.
 */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staff('admin');
    }

    private function staff(string $role, array $user = []): User
    {
        $account = User::factory()->create(['userType' => 'librarian'] + $user);
        Librarian::create(['librarianID' => $account->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $account;
    }

    private function newStaff(array $overrides = []): array
    {
        return $overrides + ['firstName' => 'Paolo', 'lastName' => 'Lim', 'email' => 'paolo@stidavao.edu.ph', 'role' => 'assistant'];
    }

    private function newMember(array $overrides = []): array
    {
        return $overrides + [
            'firstName' => 'Roberto', 'lastName' => 'Alcantara', 'email' => 'roberto@davao.sti.edu.ph',
            'memberType' => 'faculty', 'employeeIDNumber' => 'EMP-2019-014', 'department' => 'Information Technology',
        ];
    }

    // ---------------------------------------------------------------- who may enter

    public function test_only_administrators_reach_the_admin_side(): void
    {
        foreach (['/api/admin/overview', '/api/admin/staff', '/api/admin/members', '/api/admin/roles', '/api/admin/audit-logs'] as $url) {
            $this->actingAs($this->admin)->getJson($url)->assertOk();
            $this->actingAs($this->staff('librarian'))->getJson($url)->assertForbidden();
            $this->actingAs($this->staff('assistant'))->getJson($url)->assertForbidden();
            $this->actingAs(Student::factory()->approved()->create()->user)->getJson($url)->assertForbidden();
        }
    }

    public function test_the_overview_counts_accounts_by_kind(): void
    {
        $this->staff('librarian');
        $this->staff('assistant');
        Student::factory()->approved()->count(2)->create();
        Student::factory()->faculty()->approved()->create();
        Student::factory()->create(['registrationStatus' => 'pending']);

        $this->actingAs($this->admin)->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('staff.admin', 1)
            ->assertJsonPath('staff.librarian', 1)
            ->assertJsonPath('staff.assistant', 1)
            ->assertJsonPath('members.student', 2)
            ->assertJsonPath('members.faculty', 1)
            ->assertJsonPath('pendingRegistrations', 1);
    }

    // ---------------------------------------------------------------- staff accounts

    public function test_the_admin_creates_a_staff_account_that_can_sign_in_with_the_temporary_password(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/staff', $this->newStaff())
            ->assertCreated()
            ->assertJsonPath('account.role', 'assistant')
            ->assertJsonPath('account.name', 'Paolo Lim');

        $password = $response->json('temporaryPassword');
        $this->assertSame(10, strlen($password));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/librarian/login', ['email' => 'paolo@stidavao.edu.ph', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('staff.role', 'assistant');

        $this->assertDatabaseHas('audit_logs', ['actorID' => $this->admin->userID, 'action' => 'account.created']);
    }

    public function test_a_staff_account_needs_a_unique_email_and_a_real_role(): void
    {
        User::factory()->create(['email' => 'taken@stidavao.edu.ph']);

        $this->actingAs($this->admin)->postJson('/api/admin/staff', $this->newStaff(['email' => 'taken@stidavao.edu.ph']))->assertJsonValidationErrors('email');
        $this->actingAs($this->admin)->postJson('/api/admin/staff', $this->newStaff(['role' => 'superuser']))->assertJsonValidationErrors('role');
        $this->actingAs($this->admin)->postJson('/api/admin/staff', [])->assertJsonValidationErrors(['firstName', 'lastName', 'email', 'role']);
    }

    public function test_an_admin_can_change_another_accounts_role_but_not_their_own(): void
    {
        $other = $this->staff('assistant');
        $otherLibrarian = $other->librarian;

        $this->actingAs($this->admin)->patchJson("/api/admin/staff/{$otherLibrarian->uuid}", ['role' => 'librarian'])
            ->assertOk()->assertJsonPath('account.role', 'librarian');

        $this->actingAs($this->admin)->patchJson("/api/admin/staff/{$this->admin->librarian->uuid}", ['role' => 'librarian'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_an_administrator_cannot_disable_or_demote_themselves_so_one_always_remains(): void
    {
        $mine = $this->admin->librarian->uuid;

        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$mine}/disable")->assertStatus(422)->assertJsonValidationErrors('account');
        $this->actingAs($this->admin)->patchJson("/api/admin/staff/{$mine}", ['role' => 'librarian'])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertNull($this->admin->fresh()->disabledAt);
        $this->assertSame('admin', $this->admin->librarian->fresh()->role);

        // Another administrator can step in, and then the first can be demoted by them.
        $second = $this->staff('admin');
        $this->actingAs($second)->patchJson("/api/admin/staff/{$mine}", ['role' => 'librarian'])->assertOk();
    }

    public function test_disabling_an_account_blocks_sign_in_revokes_tokens_and_can_be_undone(): void
    {
        $assistant = $this->staff('assistant', ['email' => 'asst@stidavao.edu.ph']);
        $assistant->createToken('auth_token');
        $uuid = $assistant->librarian->uuid;

        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$uuid}/disable")->assertOk();

        $this->assertNotNull($assistant->fresh()->disabledAt);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $assistant->userID)->count());
        $this->postJson('/api/librarian/login', ['email' => 'asst@stidavao.edu.ph', 'password' => 'password'])->assertStatus(422);

        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$uuid}/enable")->assertOk();
        $this->assertNull($assistant->fresh()->disabledAt);
        $this->postJson('/api/librarian/login', ['email' => 'asst@stidavao.edu.ph', 'password' => 'password'])->assertOk();
    }

    public function test_a_reset_password_replaces_the_old_one_and_ends_sessions(): void
    {
        $assistant = $this->staff('assistant', ['email' => 'asst@stidavao.edu.ph']);
        $assistant->createToken('auth_token');

        $new = $this->actingAs($this->admin)->postJson("/api/admin/staff/{$assistant->librarian->uuid}/reset-password")
            ->assertOk()->json('temporaryPassword');

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $assistant->userID)->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/librarian/login', ['email' => 'asst@stidavao.edu.ph', 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/librarian/login', ['email' => 'asst@stidavao.edu.ph', 'password' => $new])->assertOk();
    }

    // ---------------------------------------------------------------- faculty and staff members

    public function test_the_admin_creates_a_faculty_member_who_can_sign_in_on_the_student_side(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/members', $this->newMember())
            ->assertCreated()
            ->assertJsonPath('account.memberType', 'faculty')
            ->assertJsonPath('account.employeeIDNumber', 'EMP-2019-014');

        $student = Student::where('studentIDNumber', 'EMP-2019-014')->firstOrFail();
        $this->assertSame('approved', $student->registrationStatus);
        $this->assertSame('Information Technology', $student->department);
        $this->assertNotEmpty($student->barcodeValue);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'roberto@davao.sti.edu.ph', 'password' => $response->json('temporaryPassword')])
            ->assertOk()
            ->assertJsonPath('user.student.memberType', 'faculty');
    }

    public function test_members_need_a_unique_employee_id_and_only_faculty_or_staff_types(): void
    {
        Student::factory()->faculty()->approved()->create(['studentIDNumber' => 'EMP-1']);

        $this->actingAs($this->admin)->postJson('/api/admin/members', $this->newMember(['employeeIDNumber' => 'EMP-1']))->assertJsonValidationErrors('employeeIDNumber');
        $this->actingAs($this->admin)->postJson('/api/admin/members', $this->newMember(['memberType' => 'student']))->assertJsonValidationErrors('memberType');
        $this->actingAs($this->admin)->postJson('/api/admin/members', $this->newMember(['department' => '']))->assertJsonValidationErrors('department');
    }

    public function test_the_members_list_has_faculty_and_staff_but_not_students_and_can_be_filtered(): void
    {
        Student::factory()->approved()->create();
        $faculty = Student::factory()->faculty()->approved()->create();
        $staff = Student::factory()->staff()->approved()->create();

        $all = $this->actingAs($this->admin)->getJson('/api/admin/members')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([(string) $faculty->uuid, (string) $staff->uuid], array_column($all, 'uuid'));

        $only = $this->actingAs($this->admin)->getJson('/api/admin/members?type=staff')->json('data');
        $this->assertSame([(string) $staff->uuid], array_column($only, 'uuid'));

        $found = $this->actingAs($this->admin)->getJson('/api/admin/members?search=' . urlencode($faculty->studentIDNumber))->json('data');
        $this->assertSame([(string) $faculty->uuid], array_column($found, 'uuid'));
    }

    public function test_a_member_can_be_edited_disabled_and_given_a_new_password_but_a_student_cannot_be_edited_here(): void
    {
        $faculty = Student::factory()->faculty()->approved()->create();
        $student = Student::factory()->approved()->create();

        $this->actingAs($this->admin)->patchJson("/api/admin/members/{$faculty->uuid}", ['department' => 'Education', 'memberType' => 'staff'])
            ->assertOk()->assertJsonPath('account.department', 'Education')->assertJsonPath('account.memberType', 'staff');

        $this->actingAs($this->admin)->patchJson("/api/admin/members/{$student->uuid}", ['department' => 'x'])->assertNotFound();

        $this->actingAs($this->admin)->postJson("/api/admin/members/{$faculty->uuid}/disable")->assertOk();
        $this->assertNotNull($faculty->user->fresh()->disabledAt);

        $this->actingAs($this->admin)->postJson("/api/admin/members/{$faculty->uuid}/reset-password")->assertOk()->assertJsonStructure(['temporaryPassword']);
    }

    // ---------------------------------------------------------------- roles

    public function test_the_roles_screen_lists_every_role_and_what_each_can_do(): void
    {
        $roles = collect($this->actingAs($this->admin)->getJson('/api/admin/roles')->assertOk()->json('roles'))->keyBy('role');

        $this->assertFalse($roles['admin']['editable']);
        $this->assertTrue($roles['assistant']['editable']);
        $this->assertSame(['circulation', 'attendance'], $roles['assistant']['permissions']);
        $this->assertContains('settings', $roles['admin']['permissions']);
    }

    public function test_saving_a_role_changes_what_its_accounts_can_do_straight_away(): void
    {
        $assistant = $this->staff('assistant');
        $this->actingAs($assistant)->getJson('/api/librarian/achievements')->assertForbidden();

        $this->actingAs($this->admin)->putJson('/api/admin/roles/assistant', ['permissions' => ['circulation', 'engagement']])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($assistant)->getJson('/api/librarian/achievements')->assertOk();
        $this->actingAs($assistant)->getJson('/api/librarian/attendance-logs')->assertForbidden();
    }

    public function test_the_admin_role_cannot_be_edited_and_unknown_permissions_are_refused(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/roles/admin', ['permissions' => []])->assertNotFound();
        $this->actingAs($this->admin)->putJson('/api/admin/roles/assistant', ['permissions' => ['launch-missiles']])->assertJsonValidationErrors('permissions.0');
        $this->actingAs($this->admin)->putJson('/api/admin/roles/assistant', [])->assertJsonValidationErrors('permissions');
    }

    // ---------------------------------------------------------------- audit log

    public function test_a_librarians_changes_are_written_to_the_audit_log_with_who_and_what(): void
    {
        $librarian = $this->staff('librarian', ['firstName' => 'Maria', 'middleInitial' => 'Y', 'lastName' => 'Santos']);
        $student = Student::factory()->create(['registrationStatus' => 'pending']);
        $student->user->update(['firstName' => 'Baby', 'middleInitial' => null, 'lastName' => 'Littel']);

        $this->actingAs($librarian)->postJson("/api/librarian/students/{$student->uuid}/approve")->assertOk();

        $entry = AuditLog::latest('logID')->first();
        $this->assertSame($librarian->userID, $entry->actorID);
        $this->assertSame('Maria Y. Santos', $entry->actorName);
        $this->assertSame('librarian', $entry->actorRole);
        $this->assertSame('student.approved', $entry->action);
        $this->assertSame("Approved a student: Baby Littel ({$student->studentIDNumber})", $entry->summary);
    }

    public function test_refused_requests_and_reads_leave_no_entry(): void
    {
        $assistant = $this->staff('assistant');
        $student = Student::factory()->create(['registrationStatus' => 'pending']);

        $this->actingAs($assistant)->postJson("/api/librarian/students/{$student->uuid}/approve")->assertForbidden();
        $this->actingAs($assistant)->getJson('/api/librarian/loans')->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_audit_log_can_be_searched_and_filtered_by_area(): void
    {
        $librarian = $this->staff('librarian');
        $student = Student::factory()->create(['registrationStatus' => 'pending']);
        $this->actingAs($librarian)->postJson("/api/librarian/students/{$student->uuid}/approve")->assertOk();
        $this->actingAs($this->admin)->postJson('/api/admin/staff', $this->newStaff())->assertCreated();

        $all = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs')->assertOk();
        $this->assertSame(2, $all->json('logs.total'));
        $this->assertArrayHasKey('student', $all->json('areas'));

        $students = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?area=student')->json('logs.data');
        $this->assertSame(['student.approved'], array_column($students, 'action'));

        $accounts = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?area=account')->json('logs.data');
        $this->assertSame(['account.created'], array_column($accounts, 'action'));

        $this->assertSame(1, $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?search=Paolo')->json('logs.total'));
    }

    public function test_every_admin_change_is_logged(): void
    {
        $assistant = $this->staff('assistant');
        $uuid = $assistant->librarian->uuid;

        $this->actingAs($this->admin)->patchJson("/api/admin/staff/{$uuid}", ['firstName' => 'Pao'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$uuid}/disable")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$uuid}/enable")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$uuid}/reset-password")->assertOk();
        $this->actingAs($this->admin)->putJson('/api/admin/roles/assistant', ['permissions' => ['reports']])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['account.updated', 'account.disabled', 'account.enabled', 'account.password-reset', 'roles.updated'],
            AuditLog::pluck('action')->all(),
        );
    }
}
