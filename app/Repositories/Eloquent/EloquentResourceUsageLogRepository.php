<?php

namespace App\Repositories\Eloquent;

use App\Models\ResourceUsageLog;
use App\Repositories\Contracts\ResourceUsageLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function paginate(?string $search, ?string $status, ?string $type, ?int $resID, ?string $date, int $perPage): LengthAwarePaginator
    {
        return ResourceUsageLog::with(['resource', 'student.user', 'staffLibrarian.user'])
            ->when($search, fn ($query, $term) => $query->where(fn ($q) => $q
                ->whereHas('resource', fn ($rq) => $rq->where('name', 'like', "%{$term}%"))
                ->orWhereHas('student', fn ($sq) => $sq->where('memberIDNumber', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")->orWhere('lastName', 'like', "%{$term}%")))))
            ->when($status === 'active', fn ($query) => $query->whereNull('endTime'))
            ->when($status === 'ended', fn ($query) => $query->whereNotNull('endTime'))
            ->when($type, fn ($query, $value) => $query->whereHas('resource', fn ($rq) => $rq->where('resourceType', $value)))
            ->when($resID, fn ($query, $value) => $query->where('resID', $value))
            ->when($date, fn ($query, $value) => $query->whereDate('startTime', $value))
            ->orderByDesc('startTime')
            ->paginate($perPage);
    }

    public function staleActive(): Collection
    {
        return ResourceUsageLog::with('resource')->whereNull('endTime')->where('startTime', '<', today())->get();
    }

    public function allActive(): Collection
    {
        return ResourceUsageLog::with('resource')->whereNull('endTime')->get();
    }

    public function activeForStudent(int $studentID): ?ResourceUsageLog
    {
        return ResourceUsageLog::with('resource')->where('studentID', $studentID)->whereNull('endTime')->first();
    }

    public function hasActiveSessionForStudent(int $studentID): bool
    {
        return ResourceUsageLog::where('studentID', $studentID)->whereNull('endTime')->exists();
    }
}
