<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** "Your account" is the same screen for every staff account, so every staff role can load it (admins included). */
class StaffAccountScreenTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, array $user = []): User
    {
        $account = User::factory()->create(['userType' => 'librarian'] + $user);
        Librarian::create(['librarianID' => $account->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $account;
    }

    public function test_an_administrator_loads_their_account_with_its_record(): void
    {
        $admin = $this->staff('admin', ['firstName' => 'Ana', 'lastName' => 'Reyes', 'phoneNumber' => '09171234567']);

        $this->actingAs($admin)->getJson('/api/librarian/settings/account')->assertOk()
            ->assertJsonPath('account.firstName', 'Ana')
            ->assertJsonPath('account.role', 'admin')
            ->assertJsonPath('account.roleLabel', 'Administrator')
            ->assertJsonPath('account.phoneNumber', '09171234567');

        // The rest of the librarian settings stay closed to them.
        $this->actingAs($admin)->getJson('/api/librarian/settings')->assertForbidden();
    }

    public function test_a_librarian_gets_the_same_account_in_the_settings_overview(): void
    {
        $librarian = $this->staff('librarian');

        $alone = $this->actingAs($librarian)->getJson('/api/librarian/settings/account')->assertOk()->json('account');
        $this->assertSame($alone, $this->actingAs($librarian)->getJson('/api/librarian/settings')->assertOk()->json('account'));
        $this->assertSame('Librarian', $alone['roleLabel']);
    }

    public function test_a_student_cannot_load_it(): void
    {
        $student = Student::factory()->approved()->create();

        $this->actingAs($student->user)->getJson('/api/librarian/settings/account')->assertForbidden();
    }
}
