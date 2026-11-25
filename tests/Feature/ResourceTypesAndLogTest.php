<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\ResourceUsageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The resource types list, the order of resources, the usage log, and sessions ending when the library closes. */
class ResourceTypesAndLogTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $this->actingAs($this->librarian);
    }

    private function type(string $name): ResourceType
    {
        return ResourceType::create(['uuid' => Str::uuid(), 'name' => $name]);
    }

    private function resource(string $name, string $type = 'Projector'): Resource
    {
        return Resource::factory()->create(['name' => $name, 'resourceType' => $type]);
    }

    private function usage(Resource $resource, Student $student, $start, $end = null): ResourceUsageLog
    {
        $resource->update(['status' => $end ? 'Available' : 'In Use']);

        return ResourceUsageLog::create(['uuid' => Str::uuid(), 'resID' => $resource->resID, 'studentID' => $student->studentID, 'staffLibrarianID' => $this->librarian->userID, 'startTime' => $start, 'endTime' => $end]);
    }

    public function test_resources_are_listed_by_the_numbers_in_their_names(): void
    {
        foreach (['Projector 11', 'Projector 3', 'Projector 28', 'Projector 7'] as $name) {
            $this->resource($name);
        }

        $names = collect($this->getJson('/api/librarian/resources')->assertOk()->json('resources'))->pluck('name')->all();

        $this->assertSame(['Projector 3', 'Projector 7', 'Projector 11', 'Projector 28'], $names);
    }

    public function test_a_resource_must_have_a_type_from_the_list_and_a_new_name(): void
    {
        $this->type('Computer');

        $this->postJson('/api/librarian/resources', ['name' => 'PC-01', 'resourceType' => 'Hoverboard'])->assertUnprocessable()->assertJsonValidationErrors('resourceType');

        // Capitals don't matter on the way in: it is saved as the list spells it.
        $this->postJson('/api/librarian/resources', ['name' => 'PC-01', 'resourceType' => 'computer'])->assertCreated()->assertJsonPath('resource.resourceType', 'Computer');

        $this->postJson('/api/librarian/resources', ['name' => 'PC-01', 'resourceType' => 'Computer'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_types_can_be_added_renamed_and_removed_but_not_while_in_use(): void
    {
        $this->postJson('/api/librarian/resource-types', ['name' => 'Study Room'])->assertCreated();
        // The same type in other capitals is the same type.
        $this->postJson('/api/librarian/resource-types', ['name' => 'study room'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $room = ResourceType::where('name', 'Study Room')->firstOrFail();
        $this->resource('Room A', 'Study Room');

        $this->deleteJson("/api/librarian/resource-types/{$room->uuid}")->assertUnprocessable();

        // Renaming follows through to the resources that have it.
        $this->patchJson("/api/librarian/resource-types/{$room->uuid}", ['name' => 'Meeting Room'])->assertOk();
        $this->assertSame('Meeting Room', Resource::where('name', 'Room A')->value('resourceType'));

        $list = $this->getJson('/api/librarian/resource-types')->assertOk()->json('types');
        $this->assertSame(1, $list[0]['resources_count']);

        Resource::where('name', 'Room A')->delete();
        $this->deleteJson("/api/librarian/resource-types/{$room->uuid}")->assertOk();
    }

    public function test_the_usage_log_is_paged_and_filtered_on_the_server(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $pc = $this->resource('PC-01', 'Computer');
        $projector = $this->resource('Projector 1', 'Projector');
        $this->usage($pc, $ana, now()->subDays(3)->setTime(9, 0), now()->subDays(3)->setTime(10, 0));
        $this->usage($projector, $ana, now()->setTime(8, 0));

        $this->getJson('/api/librarian/resource-usage-logs')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/librarian/resource-usage-logs?status=active')->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/resource-usage-logs?status=ended')->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/resource-usage-logs?type=Computer')->assertJsonPath('total', 1);
        $this->getJson("/api/librarian/resource-usage-logs?resID={$projector->resID}")->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/resource-usage-logs?date='.now()->subDays(3)->toDateString())->assertJsonPath('total', 1);
        $this->getJson('/api/librarian/resource-usage-logs?search='.$ana->user->firstName)->assertJsonPath('total', 2);
        // Who started it comes with the row.
        $this->getJson('/api/librarian/resource-usage-logs?status=active')->assertJsonStructure(['data' => [['staff_librarian' => ['user']]]]);
    }

    public function test_only_students_in_the_library_are_offered_for_a_session(): void
    {
        $inside = Student::factory()->create(['registrationStatus' => 'approved']);
        $outside = Student::factory()->create(['registrationStatus' => 'approved']);
        $earlier = Student::factory()->create(['registrationStatus' => 'approved']);
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $inside->studentID, 'entryTime' => now()]);
        AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $earlier->studentID, 'entryTime' => now()->subDays(2)]);

        $found = collect($this->getJson('/api/librarian/students?status=approved&inLibrary=1')->assertOk()->json('data'))->pluck('studentID')->all();

        $this->assertSame([$inside->studentID], $found);
        $this->assertNotContains($outside->studentID, $found);
    }

    public function test_the_nightly_clean_up_ends_sessions_left_from_an_earlier_day_at_closing_time(): void
    {
        config(['attendance.auto_close' => 'closing_time', 'attendance.closing_time' => '17:00']);
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $pc = $this->resource('PC-01', 'Computer');
        $started = now()->subDays(2)->setTime(9, 0);
        $session = $this->usage($pc, $ana, $started);

        app(AttendanceService::class)->closeStaleVisits();

        $this->assertNotNull($session->fresh()->endTime);
        $this->assertSame('17:00', $session->fresh()->endTime->format('H:i'));
        $this->assertSame($started->toDateString(), $session->fresh()->endTime->toDateString());
        $this->assertSame('Available', $pc->fresh()->status);
    }

    public function test_ending_the_station_session_ends_every_running_session(): void
    {
        $ana = Student::factory()->create(['registrationStatus' => 'approved']);
        $pc = $this->resource('PC-01', 'Computer');
        // A session with no open visit behind it (the visit was closed some other way).
        $session = $this->usage($pc, $ana, now()->subHour());

        $this->postJson('/api/librarian/attendance-logs/close-all')->assertOk()->assertJsonPath('sessionsEnded', 1);

        $this->assertNotNull($session->fresh()->endTime);
        $this->assertSame('Available', $pc->fresh()->status);
    }
}
