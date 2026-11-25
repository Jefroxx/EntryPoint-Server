<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\MarketItem;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\PointRedemption;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceUsageLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** What the Reports page adds beyond the books: a custom range, computers and rooms, fines, reservations and rewards. */
class ReportSectionsTest extends TestCase
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

    public function test_a_custom_range_is_used_and_checked(): void
    {
        $from = now()->subDays(9)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $this->getJson("/api/librarian/reports/overview?from={$from}&to={$to}")
            ->assertOk()->assertJsonPath('period.from', $from)->assertJsonPath('period.to', $to);

        // Backwards, in the future and too long are all refused.
        $this->getJson("/api/librarian/reports/overview?from={$to}&to={$from}")->assertUnprocessable();
        $this->getJson('/api/librarian/reports/overview?from='.now()->toDateString().'&to='.now()->addDays(3)->toDateString())->assertUnprocessable();
        $this->getJson('/api/librarian/reports/overview?from='.now()->subYears(5)->toDateString().'&to='.now()->toDateString())->assertUnprocessable();
    }

    public function test_the_trend_compares_with_an_equally_long_period_just_before(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        // A 10-day range: 4 visits in it, 2 in the 10 days before.
        foreach ([1, 3, 5, 8] as $daysAgo) {
            AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => now()->subDays($daysAgo), 'exitTime' => now()->subDays($daysAgo)->addHour()]);
        }
        foreach ([12, 15] as $daysAgo) {
            AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'entryTime' => now()->subDays($daysAgo), 'exitTime' => now()->subDays($daysAgo)->addHour()]);
        }

        $from = now()->subDays(9)->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/librarian/reports/overview?from={$from}&to={$to}")
            ->assertJsonPath('trends.visits.current', 4)->assertJsonPath('trends.visits.previous', 2)->assertJsonPath('trends.visits.change', 100);
    }

    public function test_computers_and_rooms_fines_reservations_and_rewards_are_reported_for_the_period(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        \App\Models\BookSubject::factory()->create();
        $book = \App\Models\Book::factory()->create(['areaOfLibrary' => 'circulation']);

        // A one-hour session on a computer, and one on a room.
        $pc = Resource::factory()->create(['resourceType' => 'Computer', 'name' => 'PC-1']);
        $room = Resource::factory()->create(['resourceType' => 'Room', 'name' => 'Room A']);
        ResourceUsageLog::create(['uuid' => Str::uuid(), 'resID' => $pc->resID, 'studentID' => $student->studentID, 'staffLibrarianID' => $this->librarian->userID, 'startTime' => now()->subDays(2)->setTime(9, 0), 'endTime' => now()->subDays(2)->setTime(10, 0)]);
        ResourceUsageLog::create(['uuid' => Str::uuid(), 'resID' => $room->resID, 'studentID' => $student->studentID, 'staffLibrarianID' => $this->librarian->userID, 'startTime' => now()->subDays(1)->setTime(9, 0), 'endTime' => now()->subDays(1)->setTime(9, 30)]);

        // Fines: 40 paid and 15 waived inside the period, 25 still owed.
        $type = PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()]);
        $fine = fn (string $status, float $amount, $settled) => Penalty::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'amount' => $amount, 'computedAt' => now(), 'paymentStatus' => $status,
            'settledAt' => $settled, 'penaltyTypeID' => $type->penaltyTypeID,
        ]);
        $fine('Paid', 40, now()->subDay());
        $fine('Waived', 15, now()->subDay());
        $fine('Unpaid', 25, null);

        // Two reservations in the period (one still waiting, one collected), and a rewards redemption.
        Reservation::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID, 'status' => 'Waiting', 'reservedAt' => now()->subDays(3)]);
        Reservation::create(['uuid' => Str::uuid(), 'studentID' => \App\Models\Student::factory()->create(['registrationStatus' => 'approved'])->studentID, 'bookID' => $book->bookID, 'status' => 'Fulfilled', 'reservedAt' => now()->subDays(3)]);
        $item = MarketItem::create(['uuid' => Str::uuid(), 'name' => 'Mug', 'pointCost' => 20, 'stock' => 5]);
        PointRedemption::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'itemID' => $item->itemID, 'quantity' => 1, 'pointsSpent' => 20, 'fulfillmentStatus' => 'Fulfilled', 'redeemedAt' => now()->subDay()]);
        PointRedemption::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'itemID' => $item->itemID, 'quantity' => 1, 'pointsSpent' => 20, 'fulfillmentStatus' => 'Cancelled', 'redeemedAt' => now()->subDay()]);

        $overview = $this->getJson('/api/librarian/reports/overview?months=3')->assertOk()->json();

        $this->assertSame(2, $overview['resources']['sessions']);
        $this->assertSame(90, $overview['resources']['minutes']);
        $this->assertSame('Computer', $overview['resources']['byType'][0]['type']);

        $this->assertEquals(40, $overview['fines']['collected']);
        $this->assertEquals(15, $overview['fines']['waived']);
        $this->assertEquals(25, $overview['fines']['outstanding']);

        $this->assertSame(2, $overview['reservations']['total']);
        $this->assertSame(1, collect($overview['reservations']['byStatus'])->firstWhere('status', 'Waiting')['count']);

        // The cancelled one was refunded, so it is not a redemption that stands.
        $this->assertSame(1, $overview['rewards']['redemptions']);
        $this->assertSame(20, $overview['rewards']['pointsSpent']);
        $this->assertSame(1, $overview['rewards']['cancelled']);

        $this->assertArrayHasKey('collection', $overview);
        $this->assertArrayHasKey('students', $overview);
    }
}
