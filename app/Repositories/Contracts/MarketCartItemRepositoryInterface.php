<?php

namespace App\Repositories\Contracts;

use App\Models\MarketCartItem;
use Illuminate\Database\Eloquent\Collection;

interface MarketCartItemRepositoryInterface extends RepositoryInterface
{
    public function findLineForStudentAndItem(int $studentID, int $itemID): ?MarketCartItem;

    public function forStudentWithItem(int $studentID): Collection;

    public function deleteByIds(array $cartItemIDs): void;
}
