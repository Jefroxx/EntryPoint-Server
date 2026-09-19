<?php

namespace App\Repositories\Eloquent;

use App\Models\PointRedemption;
use App\Repositories\Contracts\PointRedemptionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentPointRedemptionRepository extends BaseRepository implements PointRedemptionRepositoryInterface
{
    public function __construct(PointRedemption $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?string $fulfillmentStatus): Collection
    {
        return PointRedemption::with(['student.user', 'item'])
            ->when($fulfillmentStatus, fn ($query) => $query->where('fulfillmentStatus', $fulfillmentStatus))
            ->orderByDesc('redeemedAt')
            ->get();
    }

    public function forStudentWithItem(int $studentID): Collection
    {
        return PointRedemption::where('studentID', $studentID)
            ->with('item')
            ->orderByDesc('redeemedAt')
            ->get();
    }
}
