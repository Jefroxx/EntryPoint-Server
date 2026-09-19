<?php

namespace App\Repositories\Eloquent;

use App\Models\MarketCartItem;
use App\Repositories\Contracts\MarketCartItemRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentMarketCartItemRepository extends BaseRepository implements MarketCartItemRepositoryInterface
{
    public function __construct(MarketCartItem $model)
    {
        parent::__construct($model);
    }

    public function findLineForStudentAndItem(int $studentID, int $itemID): ?MarketCartItem
    {
        return MarketCartItem::where('studentID', $studentID)
            ->where('itemID', $itemID)
            ->first();
    }

    public function forStudentWithItem(int $studentID): Collection
    {
        return MarketCartItem::where('studentID', $studentID)->with('item')->get();
    }

    public function deleteByIds(array $cartItemIDs): void
    {
        MarketCartItem::whereIn('cartItemID', $cartItemIDs)->delete();
    }
}
