<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Importing a list of students, faculty and staff: checked row by row first, then created on temporary passwords.
 */
class ImportMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->admin->userID, 'uuid' => Str::uuid(), 'role' => 'admin']);
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'faculty', 'idNumber' => 'EMP-001', 'firstName' => 'Roberto', 'middleInitial' => 'M', 'lastName' => 'Alcantara',
            'email' => 'roberto@davao.sti.edu.ph', 'programOrDepartment' => 'Information Technology', 'phoneNumber' => '',
        ];
    }

    public function test_a_dry_run_reports_each_row_and_creates_nothing(): void
    {
        $rows = [
            $this->row(),
            $this->row(['idNumber' => 'EMP-002', 'email' => 'bad-email']),
            $this->row(['idNumber' => 'EMP-003', 'email' => 'liza@davao.sti.edu.ph', 'type' => 'wizard']),
        ];

        $result = $this->actingAs($this->admin)->postJson('/api/admin/members/import', ['rows' => $rows, 'dryRun' => true])->assertOk();

        $this->assertSame(1, $result->json('creatable'));
        $this->assertSame(2, $result->json('skipped'));
        $this->assertTrue($result->json('rows.0.ok'));
        $this->assertArrayHasKey('email', $result->json('rows.1.errors'));
        $this->assertArrayHasKey('type', $result->json('rows.2.errors'));

        $this->assertSame(0, Student::where('memberType', '!=', 'student')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_import_creates_the_valid_rows_with_temporary_passwords_and_skips_the_rest(): void
    {
        $rows = [
            $this->row(),
            $this->row(['type' => 'staff', 'idNumber' => 'EMP-002', 'firstName' => 'Gerald', 'lastName' => 'Navarro', 'email' => 'gerald@davao.sti.edu.ph', 'programOrDepartment' => "Registrar's Office"]),
            $this->row(['type' => 'student', 'idNumber' => '02-2024-100', 'firstName' => 'Mia', 'lastName' => 'Cruz', 'email' => 'mia@davao.sti.edu.ph', 'programOrDepartment' => 'BS Nursing']),
            $this->row(['idNumber' => 'EMP-004', 'email' => 'no-department@davao.sti.edu.ph', 'programOrDepartment' => '']),
        ];

        $result = $this->actingAs($this->admin)->postJson('/api/admin/members/import', ['rows' => $rows])->assertCreated();

        $this->assertCount(3, $result->json('created'));
        $this->assertCount(1, $result->json('skipped'));
        $this->assertSame(4, $result->json('skipped.0.row'));

        $faculty = Student::where('studentIDNumber', 'EMP-001')->firstOrFail();
        $this->assertSame('faculty', $faculty->memberType);
        $this->assertSame('Information Technology', $faculty->department);
        $this->assertNull($faculty->academicProgram);
        $this->assertSame('approved', $faculty->registrationStatus);
        $this->assertTrue($faculty->user->mustChangePassword);

        // A student gets a program, not a department, and counts as a student.
        $student = Student::where('studentIDNumber', '02-2024-100')->firstOrFail();
        $this->assertSame('student', $student->memberType);
        $this->assertSame('BS Nursing', $student->academicProgram);
        $this->assertNull($student->department);

        // The temporary password handed back is the one that works.
        $password = collect($result->json('created'))->firstWhere('idNumber', 'EMP-001')['temporaryPassword'];
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'roberto@davao.sti.edu.ph', 'password' => $password])->assertOk();

        $this->assertSame('account.imported', AuditLog::latest('logID')->first()->action);
        $this->assertStringContainsString('Imported 3 accounts', AuditLog::latest('logID')->first()->summary);
    }

    public function test_duplicates_inside_the_file_and_against_existing_accounts_are_skipped(): void
    {
        User::factory()->create(['email' => 'taken@davao.sti.edu.ph']);
        Student::factory()->faculty()->approved()->create(['studentIDNumber' => 'EMP-TAKEN']);

        $rows = [
            $this->row(),
            $this->row(['idNumber' => 'EMP-009']),                                                       // same email as row 1
            $this->row(['email' => 'second@davao.sti.edu.ph']),                                          // same ID as row 1
            $this->row(['idNumber' => 'EMP-010', 'email' => 'taken@davao.sti.edu.ph']),                  // email already an account
            $this->row(['idNumber' => 'EMP-TAKEN', 'email' => 'fresh@davao.sti.edu.ph']),                // ID already a member
        ];

        $result = $this->actingAs($this->admin)->postJson('/api/admin/members/import', ['rows' => $rows, 'dryRun' => true])->assertOk();

        $this->assertSame(1, $result->json('creatable'));
        $this->assertArrayHasKey('email', $result->json('rows.1.errors'));
        $this->assertArrayHasKey('idNumber', $result->json('rows.2.errors'));
        $this->assertArrayHasKey('email', $result->json('rows.3.errors'));
        $this->assertArrayHasKey('idNumber', $result->json('rows.4.errors'));
    }

    public function test_only_administrators_can_import_and_the_list_has_a_size_limit(): void
    {
        $librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $this->actingAs($librarian)->postJson('/api/admin/members/import', ['rows' => [$this->row()]])->assertForbidden();

        $this->actingAs($this->admin)->postJson('/api/admin/members/import', ['rows' => []])->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/api/admin/members/import', ['rows' => array_fill(0, 501, $this->row())])->assertStatus(422);
    }
}
