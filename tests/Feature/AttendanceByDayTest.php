<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The attendance log can be browsed one day at a time. */
class AttendanceByDayTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function visit(Student $student, string $in, ?string $out): void
    {
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => $in, 'exitTime' => $out]);
    }

    public function test_the_log_and_its_numbers_can_be_read_for_one_day(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $ben = Student::factory()->create(['registrationStatus' => 'approved']);
        $day = now()->subDays(3)->toDateString();

        // Ana came twice that day, Ben once; Ben was also in on another day.
        $this->visit($ana, "{$day} 08:00:00", "{$day} 09:00:00");
        $this->visit($ana, "{$day} 13:00:00", "{$day} 13:30:00");
        $this->visit($ben, "{$day} 10:00:00", "{$day} 11:00:00");
        $this->visit($ben, now()->toDateString().' 08:00:00', null);
        AttendanceLog::create(['uuid' => Str::uuid(), 'visitorName' => 'Maria Santos', 'visitorPurpose' => 'Research', 'entryTime' => "{$day} 14:00:00"]);

        $stats = $this->actingAs($this->librarian)->getJson("/api/librarian/attendance-logs/stats?date={$day}")->assertOk()->json();

        $this->assertSame($day, $stats['date']);
        $this->assertSame(4, $stats['day']['visits']);
        $this->assertSame(2, $stats['day']['students']);       // Ana and Ben, Ana counted once
        $this->assertSame(1, $stats['day']['visitors']);
        $this->assertEquals(50.0, $stats['day']['averageMinutes']);   // (60 + 30 + 60) / 3
        // The live number ignores the chosen day: Ben (today) and the visitor, who was never checked out, are still "in".
        $this->assertSame(2, $stats['currentlyInLibrary']);

        $this->actingAs($this->librarian)->getJson("/api/librarian/attendance-logs?date={$day}")
            ->assertOk()->assertJsonPath('total', 4);
        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs?date='.now()->toDateString())
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_the_stats_default_to_today_and_reject_a_bad_date(): void
    {
        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats')
            ->assertOk()->assertJsonPath('date', today()->toDateString())->assertJsonPath('day.visits', 0);

        $this->actingAs($this->librarian)->getJson('/api/librarian/attendance-logs/stats?date=yesterday')->assertUnprocessable();
    }
}
