<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    private function member(?string $program): Student
    {
        return Student::factory()->create(['registrationStatus' => 'approved', 'academicProgram' => $program]);
    }

    private function visit(Student $student, string $at): void
    {
        AttendanceLog::factory()->create(['studentID' => $student->studentID, 'entryTime' => $at, 'exitTime' => Carbon::parse($at)->addHour()]);
    }

    public function test_weekly_visits_count_every_scan_and_visitors_count_each_student_once(): void
    {
        $ana = $this->member('BSIT');
        $ben = $this->member('BSCS');

        // Week of Sep 1: Ana twice, Ben once → 3 visits, 2 different students.
        $this->visit($ana, '2026-09-01 09:00:00');
        $this->visit($ana, '2026-09-03 09:00:00');
        $this->visit($ben, '2026-09-02 09:00:00');
        // Week of Sep 8: Ana once.
        $this->visit($ana, '2026-09-09 09:00:00');

        $response = $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/borrowing-overview?month=2026-09')
            ->assertOk();

        $this->assertSame([3, 1, 0, 0, 0], $response->json('visits'));
        $this->assertSame([2, 1, 0, 0, 0], $response->json('visitors'));
        $this->assertSame(2, $response->json('visitorsTotal'));
    }

    public function test_members_by_program_keeps_the_top_five_and_folds_the_rest_into_other(): void
    {
        foreach (['BSIT' => 4, 'BSCS' => 3, 'BSBA' => 2, 'BSHM' => 2, 'BSN' => 1, 'BSED' => 1, 'BSA' => 1] as $program => $count) {
            foreach (range(1, $count) as $_) {
                $this->member($program);
            }
        }
        $this->member(null);
        Student::factory()->create(['academicProgram' => 'BSIT']); // pending: not a member yet

        $response = $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/demographics')
            ->assertOk()
            ->assertJsonPath('total', 15);

        $slices = collect($response->json('slices'));
        $this->assertCount(6, $slices);
        $this->assertSame(['label' => 'BSIT', 'count' => 4], $slices->first());
        $this->assertSame(['label' => 'Other', 'count' => 3], $slices->last()); // four programs of 1: one keeps a slot
    }

    public function test_visitors_scope_counts_each_student_once_for_the_month(): void
    {
        $ana = $this->member('BSIT');
        $ben = $this->member('BSIT');
        $cara = $this->member('BSCS');

        $this->visit($ana, '2026-09-01 09:00:00');
        $this->visit($ana, '2026-09-15 09:00:00');
        $this->visit($ben, '2026-09-10 09:00:00');
        $this->visit($cara, '2026-08-20 09:00:00'); // other month

        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/demographics?scope=visitors&month=2026-09')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('slices', [['label' => 'BSIT', 'count' => 2]]);
    }

    public function test_it_rejects_an_unknown_scope(): void
    {
        $this->actingAs($this->librarian())
            ->getJson('/api/librarian/dashboard/demographics?scope=everyone')
            ->assertUnprocessable();
    }
}
