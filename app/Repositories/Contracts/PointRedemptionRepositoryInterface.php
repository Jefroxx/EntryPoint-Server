<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface PointRedemptionRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?string $fulfillmentStatus): Collection;
}
