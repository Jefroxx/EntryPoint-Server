<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The librarian Dashboard loads in one request: the same pieces its five separate routes answer. */
class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $account = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $account->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $account;
    }

    public function test_one_request_brings_everything_the_dashboard_shows(): void
    {
        $librarian = $this->staff('librarian');

        $overview = $this->actingAs($librarian)->getJson('/api/librarian/dashboard')->assertOk()
            ->assertJsonStructure(['today' => ['inLibrary', 'visitsToday', 'overdue', 'attention' => ['registrations', 'unpaidTotal']],
                'recentLoans', 'overdueLoans', 'hallOfFame', 'announcements'])
            ->json();

        // Each piece is exactly what its own route says.
        $this->assertSame($this->getJson('/api/librarian/dashboard/today')->json(), $overview['today']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/recent-loans')->json(), $overview['recentLoans']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/overdue-loans')->json(), $overview['overdueLoans']);
        $this->assertSame($this->getJson('/api/librarian/announcements')->json('announcements'), $overview['announcements']);
    }

    public function test_the_trends_section_is_one_request_for_the_chosen_month(): void
    {
        $this->actingAs($this->staff('librarian'));

        $trends = $this->getJson('/api/librarian/dashboard/trends?month=2026-10')->assertOk()->json();

        $this->assertSame($this->getJson('/api/librarian/dashboard/summary')->json(), $trends['summary']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/book-status')->json(), $trends['bookStatus']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/borrowing-overview?month=2026-10')->json(), $trends['borrowingOverview']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/demographics?scope=members')->json(), $trends['demographics']['members']);
        $this->assertSame($this->getJson('/api/librarian/dashboard/demographics?scope=visitors&month=2026-10')->json(), $trends['demographics']['visitors']);

        $this->getJson('/api/librarian/dashboard/trends?month=October')->assertStatus(422);
    }

    public function test_a_student_assistant_can_load_it_and_an_administrator_cannot(): void
    {
        $this->actingAs($this->staff('assistant'))->getJson('/api/librarian/dashboard')->assertOk();
        $this->actingAs($this->staff('admin'))->getJson('/api/librarian/dashboard')->assertForbidden();
    }
}
