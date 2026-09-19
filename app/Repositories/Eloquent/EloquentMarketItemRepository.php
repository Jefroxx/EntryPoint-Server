<?php

namespace App\Repositories\Eloquent;

use App\Models\MarketItem;
use App\Repositories\Contracts\MarketItemRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentMarketItemRepository extends BaseRepository implements MarketItemRepositoryInterface
{
    public function __construct(MarketItem $model)
    {
        parent::__construct($model);
    }

    public function withRedemptionCounts(): Collection
    {
        return MarketItem::withCount('redemptions')->orderBy('name')->get();
    }

    public function orderedByPointCost(): Collection
    {
        return MarketItem::orderBy('pointCost')->get();
    }

    public function hasRedemptionHistory(MarketItem $item): bool
    {
        return $item->redemptions()->exists();
    }

    public function lockForUpdate(int $itemID): MarketItem
    {
        return MarketItem::lockForUpdate()->findOrFail($itemID);
    }

    public function decrementStock(MarketItem $item, int $amount): void
    {
        $item->decrement('stock', $amount);
    }

    public function incrementStock(MarketItem $item, int $amount): void
    {
        $item->increment('stock', $amount);
    }
}
