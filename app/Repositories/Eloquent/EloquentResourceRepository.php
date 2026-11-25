<?php

namespace App\Repositories\Eloquent;

use App\Models\Resource;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentResourceRepository extends BaseRepository implements ResourceRepositoryInterface
{
    public function __construct(Resource $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?string $resourceType, ?string $status): Collection
    {
        return Resource::with('activeUsage.student.user')
            ->when($resourceType, fn ($query) => $query->where('resourceType', $resourceType))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->get()
            // By the numbers in the name, not letter by letter: Projector 3 comes before Projector 11.
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function studentListWithFilters(?string $resourceType): Collection
    {
        return Resource::query()
            ->when($resourceType, fn ($query) => $query->where('resourceType', $resourceType))
            ->get(['resID', 'resourceType', 'name', 'status'])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function lockForUpdate(int $resID): Resource
    {
        return Resource::lockForUpdate()->findOrFail($resID);
    }
}
