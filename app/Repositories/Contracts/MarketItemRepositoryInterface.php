<?php

namespace App\Repositories\Contracts;

use App\Models\MarketItem;
use Illuminate\Database\Eloquent\Collection;

interface MarketItemRepositoryInterface extends RepositoryInterface
{
    public function withRedemptionCounts(): Collection;

    public function orderedByPointCost(): Collection;

    public function hasRedemptionHistory(MarketItem $item): bool;

    public function lockForUpdate(int $itemID): MarketItem;

    public function decrementStock(MarketItem $item, int $amount): void;

    public function incrementStock(MarketItem $item, int $amount): void;
}
