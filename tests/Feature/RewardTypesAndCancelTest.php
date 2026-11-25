<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\MarketItem;
use App\Models\MarketItemType;
use App\Models\PointRedemption;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The reward types list, cancelling a redemption with a reason, and the low-stock setting. */
class RewardTypesAndCancelTest extends TestCase
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

    private function item(string $name, ?string $type = null, int $stock = 5): MarketItem
    {
        return MarketItem::create(['uuid' => Str::uuid(), 'name' => $name, 'type' => $type, 'pointCost' => 10, 'stock' => $stock]);
    }

    public function test_a_reward_must_have_a_type_from_the_list(): void
    {
        MarketItemType::create(['uuid' => Str::uuid(), 'name' => 'Merchandise']);

        $this->postJson('/api/librarian/market-items', ['name' => 'Mug', 'type' => 'Hoverboard', 'pointCost' => 5, 'stock' => 3])->assertUnprocessable()->assertJsonValidationErrors('type');

        // Capitals don't matter on the way in; it is saved as the list spells it. A type is still optional.
        $this->postJson('/api/librarian/market-items', ['name' => 'Mug', 'type' => 'merchandise', 'pointCost' => 5, 'stock' => 3])->assertCreated()->assertJsonPath('item.type', 'Merchandise');
        $this->postJson('/api/librarian/market-items', ['name' => 'Pen', 'pointCost' => 5, 'stock' => 3])->assertCreated();
    }

    public function test_reward_types_can_be_added_renamed_and_removed_but_not_while_in_use(): void
    {
        $this->postJson('/api/librarian/market-item-types', ['name' => 'Voucher'])->assertCreated();
        $this->postJson('/api/librarian/market-item-types', ['name' => 'voucher'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $type = MarketItemType::where('name', 'Voucher')->firstOrFail();
        $this->item('Print voucher', 'Voucher');

        $this->deleteJson("/api/librarian/market-item-types/{$type->uuid}")->assertUnprocessable();

        $this->patchJson("/api/librarian/market-item-types/{$type->uuid}", ['name' => 'Coupon'])->assertOk();
        $this->assertSame('Coupon', MarketItem::where('name', 'Print voucher')->value('type'));
        $this->assertSame(1, $this->getJson('/api/librarian/market-item-types')->assertOk()->json('types.0.items_count'));

        MarketItem::where('name', 'Print voucher')->delete();
        $this->deleteJson("/api/librarian/market-item-types/{$type->uuid}")->assertOk();
    }

    public function test_cancelling_a_redemption_needs_a_reason_the_student_is_told(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved', 'knowledgeScore' => 0]);
        $item = $this->item('Mug', null, 2);
        $redemption = PointRedemption::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'itemID' => $item->itemID, 'quantity' => 1,
            'pointsSpent' => 10, 'fulfillmentStatus' => 'Pending', 'redeemedAt' => now(),
        ]);

        $this->postJson("/api/librarian/point-redemptions/{$redemption->uuid}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('Pending', $redemption->fresh()->fulfillmentStatus);

        $this->postJson("/api/librarian/point-redemptions/{$redemption->uuid}/cancel", ['reason' => 'The mugs ran out'])->assertOk();

        $this->assertSame('Cancelled', $redemption->fresh()->fulfillmentStatus);
        $this->assertSame('The mugs ran out', $redemption->fresh()->cancelReason);
        $this->assertSame(10, $student->fresh()->knowledgeScore);
        $this->assertSame(3, $item->fresh()->stock);
        $this->assertTrue(SystemNotification::where('userID', $student->studentID)->where('type', 'redemption_cancelled')
            ->where('message', 'like', '%The mugs ran out%')->exists());
    }

    public function test_the_low_stock_level_is_a_setting(): void
    {
        $this->getJson('/api/librarian/market-items')->assertOk()->assertJsonPath('lowStockAt', 3);

        $this->putJson('/api/librarian/settings/rewards', ['lowStockAt' => 8])->assertOk();
        $this->getJson('/api/librarian/market-items')->assertJsonPath('lowStockAt', 8);
        $this->getJson('/api/librarian/settings')->assertJsonPath('lowStockAt', 8);

        $this->putJson('/api/librarian/settings/rewards', ['lowStockAt' => -1])->assertUnprocessable();
    }
}
