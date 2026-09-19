<?php

namespace App\Repositories\Eloquent;

use App\Models\ResourceUsageLog;
use App\Repositories\Contracts\ResourceUsageLogRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentResourceUsageLogRepository extends BaseRepository implements ResourceUsageLogRepositoryInterface
{
    public function __construct(ResourceUsageLog $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?int $resID, bool $activeOnly): Collection
    {
        return ResourceUsageLog::with(['resource', 'student.user', 'staffLibrarian.user'])
            ->when($resID, fn ($query) => $query->where('resID', $resID))
            ->when($activeOnly, fn ($query) => $query->whereNull('endTime'))
            ->orderByDesc('startTime')
            ->get();
    }

    public function hasActiveSessionForStudent(int $studentID): bool
    {
        return ResourceUsageLog::where('studentID', $studentID)->whereNull('endTime')->exists();
    }
}
