<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'itemID';

    protected $fillable = ['uuid', 'name', 'type', 'pointCost', 'stock'];

    public function redemptions()
    {
        return $this->hasMany(PointRedemption::class, 'itemID', 'itemID');
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Spend a student's points on `$quantity` units of this item: locks the
     * row, checks stock and affordability, deducts both, and records one
     * PointRedemption line. Shared by single-item redeem and cart checkout
     * so both go through identical stock/affordability rules.
     */
    public function redeemFor(Student $student, int $quantity = 1): PointRedemption
    {
        $item = self::lockForUpdate()->findOrFail($this->itemID);

        if ($item->stock < $quantity) {
            throw new \RuntimeException(
                "\"{$item->name}\" only has {$item->stock} left in stock (you asked for {$quantity})."
            );
        }

        $totalCost = $item->pointCost * $quantity;

        if ($student->knowledgeScore < $totalCost) {
            throw new \RuntimeException(
                "You need {$totalCost} points for \"{$item->name}\" x{$quantity}, but you only have {$student->knowledgeScore}."
            );
        }

        $item->decrement('stock', $quantity);
        $student->update(['knowledgeScore' => $student->knowledgeScore - $totalCost]);

        return PointRedemption::create([
            'uuid'              => Str::uuid(),
            'studentID'         => $student->studentID,
            'itemID'            => $item->itemID,
            'quantity'          => $quantity,
            'pointsSpent'       => $totalCost,
            'fulfillmentStatus' => 'Pending',
            'redeemedAt'        => now(),
        ]);
    }
}
