<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\HallOfFameService;
use App\Services\StaffPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Staff roles (administrator, librarian, student assistant) and member types (student, faculty, staff).
 */
class StaffRolesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    // ---------------------------------------------------------------- permissions

    public function test_a_librarian_can_use_every_area(): void
    {
        $librarian = $this->staff('librarian');

        foreach (['/api/librarian/loans', '/api/librarian/students', '/api/librarian/achievements', '/api/librarian/reports/overview', '/api/librarian/resources'] as $url) {
            $this->actingAs($librarian)->getJson($url)->assertOk();
        }
    }

    public function test_a_student_assistant_can_run_the_desk_but_not_the_rest(): void
    {
        $assistant = $this->staff('assistant');

        // Circulation and attendance are theirs, and looking students and books up for a checkout is allowed.
        foreach (['/api/librarian/loans', '/api/librarian/attendance-logs', '/api/librarian/students', '/api/librarian/copies'] as $url) {
            $this->actingAs($assistant)->getJson($url)->assertOk();
        }

        // Reading the resource list is allowed (the scanner starts sessions on them); managing resources, engagement and reports is not.
        $this->actingAs($assistant)->getJson('/api/librarian/resources')->assertOk();
        foreach (['/api/librarian/achievements', '/api/librarian/market-items', '/api/librarian/reports/overview', '/api/librarian/resource-usage-logs'] as $url) {
            $this->actingAs($assistant)->getJson($url)->assertForbidden();
        }
    }

    public function test_an_assistant_can_look_students_up_but_not_approve_them(): void
    {
        $assistant = $this->staff('assistant');
        $student = Student::factory()->create(['registrationStatus' => 'pending']);

        $this->actingAs($assistant)->postJson("/api/librarian/students/{$student->uuid}/approve")->assertForbidden();
        $this->assertSame('pending', $student->fresh()->registrationStatus);

        $this->actingAs($this->staff('librarian'))->postJson("/api/librarian/students/{$student->uuid}/approve")->assertOk();
    }

    public function test_everyone_can_read_settings_and_manage_their_own_account_but_only_settings_staff_change_loan_periods(): void
    {
        $assistant = $this->staff('assistant');

        $this->actingAs($assistant)->getJson('/api/librarian/settings')->assertOk();
        $this->actingAs($assistant)->putJson('/api/librarian/settings/loan-periods', [])->assertForbidden();
        $this->actingAs($assistant)->deleteJson('/api/librarian/settings/fine-rules/circulation')->assertForbidden();

        // The account and password routes aren't behind the 'settings' permission (these fail validation, not the role check).
        $this->assertNotSame(403, $this->actingAs($assistant)->patchJson('/api/librarian/settings/account', [])->getStatusCode());
        $this->assertNotSame(403, $this->actingAs($assistant)->putJson('/api/librarian/settings/password', [])->getStatusCode());
    }

    public function test_the_administrator_has_everything_and_cannot_be_limited(): void
    {
        $service = app(StaffPermissionService::class);

        $this->assertEqualsCanonicalizing($service->keys(), $service->forRole('admin'));
        $this->expectException(\InvalidArgumentException::class);
        $service->set('admin', ['reports']);
    }

    public function test_a_role_uses_the_list_the_administrator_saved_even_when_it_is_empty(): void
    {
        $service = app(StaffPermissionService::class);
        $assistant = $this->staff('assistant');

        $service->set('assistant', ['reports']);
        $this->assertSame(['reports'], $service->forRole('assistant'));
        $this->actingAs($assistant)->getJson('/api/librarian/reports/overview')->assertOk();
        $this->actingAs($assistant)->getJson('/api/librarian/loans')->assertForbidden();

        // Unticking everything must stay "nothing", not fall back to the defaults.
        $service->set('assistant', []);
        $this->assertSame([], $service->forRole('assistant'));
        $this->actingAs($assistant)->getJson('/api/librarian/reports/overview')->assertForbidden();
    }

    public function test_unknown_permissions_are_dropped_when_saving(): void
    {
        $service = app(StaffPermissionService::class);
        $service->set('librarian', ['circulation', 'launch-missiles']);

        $this->assertSame(['circulation'], $service->forRole('librarian'));
    }

    public function test_me_tells_the_screens_the_role_and_permissions(): void
    {
        $this->actingAs($this->staff('assistant'))->getJson('/api/librarian/me')
            ->assertOk()
            ->assertJson(['role' => 'assistant', 'isAdmin' => false, 'permissions' => ['circulation', 'attendance']]);

        $this->actingAs($this->staff('admin'))->getJson('/api/librarian/me')
            ->assertOk()
            ->assertJson(['role' => 'admin', 'isAdmin' => true]);
    }

    public function test_the_staff_login_returns_the_role(): void
    {
        $assistant = $this->staff('assistant');

        $this->postJson('/api/librarian/login', ['email' => $assistant->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('staff.role', 'assistant')
            ->assertJsonPath('staff.permissions', ['circulation', 'attendance']);
    }

    public function test_the_admin_middleware_lets_only_administrators_through(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'admin'])->get('/api/_admin-probe', fn () => response()->json(['ok' => true]));

        $this->actingAs($this->staff('admin'))->getJson('/api/_admin-probe')->assertOk();
        $this->actingAs($this->staff('librarian'))->getJson('/api/_admin-probe')->assertForbidden();
        $this->actingAs($this->staff('assistant'))->getJson('/api/_admin-probe')->assertForbidden();
        $this->actingAs(Student::factory()->approved()->create()->user)->getJson('/api/_admin-probe')->assertForbidden();
    }

    // ---------------------------------------------------------------- disabled accounts

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $assistant = $this->staff('assistant');
        $assistant->update(['disabledAt' => now()]);

        $this->postJson('/api/librarian/login', ['email' => $assistant->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_disabled_account_is_stopped_on_its_next_request_and_its_tokens_are_revoked(): void
    {
        $librarian = $this->staff('librarian');
        $token = $librarian->createToken('auth_token')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/librarian/loans')->assertOk();

        $librarian->update(['disabledAt' => now()]);
        $this->app['auth']->forgetGuards(); // the test app would otherwise keep the user from the request above

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/librarian/loans')->assertForbidden();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $librarian->userID)->count());
    }

    // ---------------------------------------------------------------- member types

    private function visit(Student $student): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => now()->subHour(), 'exitTime' => now()]);
    }

    public function test_faculty_and_staff_can_borrow_things_but_are_shut_out_of_points_and_the_shop(): void
    {
        $faculty = Student::factory()->faculty()->approved()->create();

        $this->actingAs($faculty->user)->getJson('/api/student/loans')->assertOk();
        $this->actingAs($faculty->user)->getJson('/api/student/catalog')->assertOk();

        foreach (['/api/student/hall-of-fame', '/api/student/achievements', '/api/student/market-items', '/api/student/market-cart', '/api/student/redemptions'] as $url) {
            $this->actingAs($faculty->user)->getJson($url)->assertForbidden();
        }

        // A student, same routes, is fine.
        $student = Student::factory()->approved()->create();
        foreach (['/api/student/hall-of-fame', '/api/student/achievements', '/api/student/market-items'] as $url) {
            $this->actingAs($student->user)->getJson($url)->assertOk();
        }
    }

    public function test_faculty_are_not_ranked_in_the_hall_of_fame(): void
    {
        $student = Student::factory()->approved()->create();
        $faculty = Student::factory()->faculty()->approved()->create();
        $this->visit($student);
        $this->visit($faculty);
        $this->visit($faculty);

        $board = app(HallOfFameService::class)->staffBoard();
        $mostActive = collect($board['categories'])->firstWhere('key', 'most-active');

        $this->assertCount(1, $mostActive['leaders']);
        $this->assertSame($student->studentIDNumber, $mostActive['leaders'][0]['studentIDNumber']);
    }

    public function test_faculty_never_unlock_achievements(): void
    {
        $achievement = Achievement::create([
            'uuid' => Str::uuid(), 'name' => 'Any points', 'criteriaJSON' => ['metric' => 'knowledgeScore', 'threshold' => 1], 'pointsReward' => 5,
        ]);
        $faculty = Student::factory()->faculty()->approved()->create(['knowledgeScore' => 50]);
        $student = Student::factory()->approved()->create(['knowledgeScore' => 50]);

        $service = app(AchievementService::class);
        $service->evaluateForStudent($faculty);
        $service->evaluateForStudent($student);
        $service->evaluateForEveryone();

        $unlocked = DB::table('student_achievement')->where('achievementID', $achievement->achievementID)->pluck('studentID')->all();
        $this->assertSame([$student->studentID], $unlocked);
    }

    public function test_a_disabled_student_side_account_is_stopped_too(): void
    {
        $faculty = Student::factory()->faculty()->approved()->create();
        $token = $faculty->user->createToken('auth_token')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/student/loans')->assertOk();

        $faculty->user->update(['disabledAt' => now()]);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/student/loans')->assertForbidden();
    }

    // ---------------------------------------------------------------- faculty and staff stay out of student numbers

    public function test_the_students_page_can_ask_for_students_only_while_lookups_still_find_faculty(): void
    {
        $librarian = $this->staff('librarian');
        $student = Student::factory()->approved()->create();
        $faculty = Student::factory()->faculty()->approved()->create();

        // The Students page: students only.
        $only = $this->actingAs($librarian)->getJson('/api/librarian/students?status=approved&memberType=student')->assertOk()->json('data');
        $this->assertSame([(string) $student->uuid], array_column($only, 'uuid'));

        // Checkout and starting a session leave the filter out, and find everyone who can borrow.
        $everyone = $this->actingAs($librarian)->getJson('/api/librarian/students?status=approved')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([(string) $student->uuid, (string) $faculty->uuid], array_column($everyone, 'uuid'));
    }

    public function test_the_student_counts_leave_faculty_out_when_asked(): void
    {
        $librarian = $this->staff('librarian');
        Student::factory()->approved()->count(2)->create();
        Student::factory()->faculty()->approved()->create();

        $this->actingAs($librarian)->getJson('/api/librarian/students/stats?memberType=student')->assertOk()->assertJsonPath('approved', 2);
        $this->actingAs($librarian)->getJson('/api/librarian/students/stats')->assertOk()->assertJsonPath('approved', 3);
    }

    public function test_students_by_program_does_not_count_faculty_or_staff(): void
    {
        Student::factory()->approved()->create(['academicProgram' => 'BS Nursing']);
        Student::factory()->faculty()->approved()->create();

        $chart = $this->actingAs($this->staff('librarian'))->getJson('/api/librarian/dashboard/demographics?scope=members')->assertOk();

        $this->assertSame(1, $chart->json('total'));
        $this->assertSame(['BS Nursing'], array_column($chart->json('slices'), 'label'));
    }

    public function test_the_members_filter_lists_faculty_and_staff_together_and_never_students(): void
    {
        $librarian = $this->staff('librarian');
        Student::factory()->approved()->create();
        $faculty = Student::factory()->faculty()->approved()->create();
        $staff = Student::factory()->staff()->approved()->create();

        $rows = $this->actingAs($librarian)->getJson('/api/librarian/students?status=approved&memberType=members')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([(string) $faculty->uuid, (string) $staff->uuid], array_column($rows, 'uuid'));
        $this->assertEqualsCanonicalizing(['faculty', 'staff'], array_column($rows, 'memberType'));
        $this->actingAs($librarian)->getJson('/api/librarian/students/stats?memberType=members')->assertOk()->assertJsonPath('approved', 2);
    }
}
