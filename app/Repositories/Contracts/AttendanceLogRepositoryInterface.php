<?php

namespace App\Repositories\Contracts;

use App\Models\AttendanceLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

interface AttendanceLogRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?int $studentID, bool $activeOnly): Collection;

    public function paginate(?string $search, ?string $date, bool $activeOnly, int $perPage): LengthAwarePaginator;

    public function openLogForStudent(int $studentID): ?AttendanceLog;

    public function recentForStudent(int $studentID, int $limit): Collection;

    public function hasVisitedToday(int $studentID): bool;

    public function lastVisitBeforeToday(int $studentID): ?AttendanceLog;

    public function currentlyInLibraryCount(): int;

    public function totalVisitsToday(): int;

    public function averageMinutesToday(): float;

    public function averageMinutesBetween(\DateTimeInterface $start, \DateTimeInterface $end): float;

    /**
     * @return BaseCollection<int,int> counts keyed by MySQL DAYOFWEEK() (1=Sunday..7=Saturday)
     */
    public function countByWeekdayBetween(\DateTimeInterface $start, \DateTimeInterface $end): BaseCollection;
}
