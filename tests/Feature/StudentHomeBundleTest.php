<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/student/home: everything the Home screen shows, in the same shapes as the separate endpoints. */
class StudentHomeBundleTest extends TestCase
{
    use RefreshDatabase;

    private const PARTS = ['loans', 'reservations', 'attendance', 'achievements', 'areas', 'recommendations', 'announcements', 'hallOfFame', 'newArrivals'];

    public function test_a_student_gets_every_part_matching_the_separate_endpoints(): void
    {
        $student = Student::factory()->approved()->create();
        $this->actingAs($student->user);

        $home = $this->getJson('/api/student/home')->assertOk()->assertJsonStructure(self::PARTS)->json();

        $this->assertSame($this->getJson('/api/student/loans')->json('loans'), $home['loans']);
        $this->assertSame($this->getJson('/api/student/reservations')->json('reservations'), $home['reservations']);
        $this->assertSame($this->getJson('/api/student/catalog/areas')->json('areas'), $home['areas']);
        $this->assertSame($this->getJson('/api/student/announcements')->json('announcements'), $home['announcements']);
        $this->assertSame($this->getJson('/api/student/attendance')->json(), $home['attendance']);
        $this->assertSame($this->getJson('/api/student/hall-of-fame')->json(), $home['hallOfFame']);
        $this->assertSame(
            $this->getJson('/api/student/catalog?newArrivalsOnly=1&perPage=10')->json('data'),
            $home['newArrivals']['data'],
        );
    }

    public function test_a_faculty_member_gets_no_achievements_or_hall_of_fame(): void
    {
        $member = Student::factory()->faculty()->approved()->create();

        $this->actingAs($member->user)->getJson('/api/student/home')->assertOk()
            ->assertJsonPath('achievements', [])
            ->assertJsonPath('hallOfFame', null);
    }

    public function test_it_needs_a_signed_in_member(): void
    {
        $this->getJson('/api/student/home')->assertUnauthorized();
    }
}
