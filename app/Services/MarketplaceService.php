<?php

namespace App\Services;

use App\Models\MarketCartItem;
use App\Models\MarketItem;
use App\Models\PointRedemption;
use App\Models\Student;
use App\Repositories\Contracts\MarketCartItemRepositoryInterface;
use App\Repositories\Contracts\MarketItemRepositoryInterface;
use App\Repositories\Contracts\PointRedemptionRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceService
{
    public function __construct(
        private MarketItemRepositoryInterface $marketItems,
        private MarketCartItemRepositoryInterface $marketCartItems,
        private PointRedemptionRepositoryInterface $pointRedemptions,
        private StudentRepositoryInterface $students,
        private NotificationService $notifications,
        private AchievementService $achievements,
    ) {
    }

    public function listItems(): Collection
    {
        return $this->marketItems->withRedemptionCounts();
    }

    public function createItem(array $validated): MarketItem
    {
        return $this->marketItems->create([
            'uuid'      => Str::uuid(),
            'name'      => $validated['name'],
            'type'      => $validated['type'] ?? null,
            'pointCost' => $validated['pointCost'],
            'stock'     => $validated['stock'],
        ]);
    }

    public function updateItem(MarketItem $item, array $validated): MarketItem
    {
        return $this->marketItems->update($item, $validated)->fresh();
    }

    public function deleteItem(MarketItem $item): void
    {
        if ($this->marketItems->hasRedemptionHistory($item)) {
            throw ValidationException::withMessages([
                'item' => ['Cannot delete: this item has redemption history. Set stock to 0 to retire it instead.'],
            ]);
        }

        $this->marketItems->delete($item);
    }

    public function studentIndex(Student $student): array
    {
        $items = $this->marketItems->orderedByPointCost()->map(fn (MarketItem $item) => [
            'itemID'     => $item->itemID,
            'name'       => $item->name,
            'type'       => $item->type,
            'pointCost'  => $item->pointCost,
            'stock'      => $item->stock,
            'inStock'    => $item->stock > 0,
            'affordable' => $student->knowledgeScore >= $item->pointCost,
        ]);

        return ['points' => $student->knowledgeScore, 'items' => $items];
    }

    public function redeemSingle(MarketItem $item, Student $student): PointRedemption
    {
        $redemption = DB::transaction(fn () => $this->redeemItem($item, $student));
        $redemption->load('item');

        $this->notifications->send(
            $student->studentID,
            "You've redeemed \"{$redemption->item->name}\" for {$redemption->pointsSpent} points. A librarian will process it shortly.",
            'redemption_requested'
        );

        $this->notifications->notifyAllLibrarians(
            "{$student->user->fullName} redeemed \"{$redemption->item->name}\" - please fulfill.",
            'new_redemption'
        );

        return $redemption;
    }

    public function cartIndex(Student $student): array
    {
        $cart = $this->marketCartItems->forStudentWithItem($student->studentID);

        return [
            'points' => $student->knowledgeScore,
            'cart'   => $cart,
            'total'  => $cart->sum(fn ($line) => $line->item->pointCost * $line->quantity),
        ];
    }

    public function addToCart(Student $student, int $itemID, int $quantity): MarketCartItem
    {
        $line = $this->marketCartItems->findLineForStudentAndItem($student->studentID, $itemID);

        $line = $line
            ? $this->marketCartItems->update($line, ['quantity' => $line->quantity + $quantity])
            : $this->marketCartItems->create([
                'uuid'      => Str::uuid(),
                'studentID' => $student->studentID,
                'itemID'    => $itemID,
                'quantity'  => $quantity,
            ]);

        return $line->load('item');
    }

    public function updateCartLine(MarketCartItem $line, int $studentID, int $quantity): MarketCartItem
    {
        if ($line->studentID !== $studentID) {
            throw new AuthorizationException('This cart line does not belong to you.');
        }

        return $this->marketCartItems->update($line, ['quantity' => $quantity])->fresh()->load('item');
    }

    public function removeCartLine(MarketCartItem $line, int $studentID): void
    {
        if ($line->studentID !== $studentID) {
            throw new AuthorizationException('This cart line does not belong to you.');
        }

        $this->marketCartItems->delete($line);
    }

    public function checkout(Student $student): Collection
    {
        $cart = $this->marketCartItems->forStudentWithItem($student->studentID);

        if ($cart->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        }

        $redemptions = DB::transaction(function () use ($cart, $student) {
            $redemptions = $cart->map(fn ($line) => $this->redeemItem($line->item, $student, $line->quantity));

            $this->marketCartItems->deleteByIds($cart->pluck('cartItemID')->toArray());

            return $redemptions;
        });

        $redemptions->each(fn ($r) => $r->load('item'));

        $itemSummary = $redemptions->map(fn ($r) => $r->quantity > 1 ? "{$r->item->name} x{$r->quantity}" : $r->item->name)->join(', ');
        $totalSpent = $redemptions->sum('pointsSpent');

        $this->notifications->send(
            $student->studentID,
            "You've redeemed {$itemSummary} for {$totalSpent} points total. A librarian will process it shortly.",
            'redemption_requested'
        );

        $this->notifications->notifyAllLibrarians(
            "{$student->user->fullName} redeemed {$itemSummary} - please fulfill.",
            'new_redemption'
        );

        return $redemptions;
    }

    public function listRedemptions(?string $status): Collection
    {
        return $this->pointRedemptions->listWithFilters($status);
    }

    public function fulfillRedemption(PointRedemption $redemption): PointRedemption
    {
        if ($redemption->fulfillmentStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'redemption' => ["Only a 'Pending' redemption can be fulfilled."],
            ]);
        }

        $this->pointRedemptions->update($redemption, ['fulfillmentStatus' => 'Fulfilled']);
        $redemption->load('item');

        $this->notifications->send(
            $redemption->studentID,
            "Your redemption of \"{$redemption->item->name}\" has been fulfilled. Enjoy!",
            'redemption_fulfilled'
        );

        return $redemption;
    }

    /**
     * Cancel a pending redemption — refunds the points and restocks the
     * item, e.g. if the physical reward turned out to be unavailable.
     */
    public function cancelRedemption(PointRedemption $redemption): PointRedemption
    {
        if ($redemption->fulfillmentStatus !== 'Pending') {
            throw ValidationException::withMessages([
                'redemption' => ["Only a 'Pending' redemption can be cancelled."],
            ]);
        }

        DB::transaction(function () use ($redemption) {
            $this->pointRedemptions->update($redemption, ['fulfillmentStatus' => 'Cancelled']);
            $this->marketItems->incrementStock($redemption->item, $redemption->quantity);
            $this->students->creditPoints($redemption->student, $redemption->pointsSpent);
        });

        // Refunding credits points, which can cross an achievement threshold.
        $this->achievements->evaluateForStudent($redemption->student);

        $redemption->load('item');

        $this->notifications->send(
            $redemption->studentID,
            "Your redemption of \"{$redemption->item->name}\" was cancelled and your {$redemption->pointsSpent} points have been refunded.",
            'redemption_cancelled'
        );

        return $redemption;
    }

    /**
     * Spend a student's points on `$quantity` units of an item: locks the
     * row, checks stock and affordability, deducts both, and records one
     * PointRedemption line. Shared by single-item redeem and cart checkout
     * so both go through identical stock/affordability rules.
     */
    private function redeemItem(MarketItem $item, Student $student, int $quantity = 1): PointRedemption
    {
        $locked = $this->marketItems->lockForUpdate($item->itemID);

        if ($locked->stock < $quantity) {
            throw ValidationException::withMessages([
                'item' => ["\"{$locked->name}\" only has {$locked->stock} left in stock (you asked for {$quantity})."],
            ]);
        }

        $totalCost = $locked->pointCost * $quantity;

        if ($student->knowledgeScore < $totalCost) {
            throw ValidationException::withMessages([
                'item' => ["You need {$totalCost} points for \"{$locked->name}\" x{$quantity}, but you only have {$student->knowledgeScore}."],
            ]);
        }

        $this->marketItems->decrementStock($locked, $quantity);
        $this->students->creditPoints($student, -$totalCost);

        return $this->pointRedemptions->create([
            'uuid'              => Str::uuid(),
            'studentID'         => $student->studentID,
            'itemID'            => $locked->itemID,
            'quantity'          => $quantity,
            'pointsSpent'       => $totalCost,
            'fulfillmentStatus' => 'Pending',
            'redeemedAt'        => now(),
        ]);
    }
}
