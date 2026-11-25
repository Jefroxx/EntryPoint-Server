<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ResourceUsageLogRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?int $resID, bool $activeOnly): Collection;

    /** One page of the log, newest first. $status: active | ended. $date: the day a session started (Y-m-d). */
    public function paginate(?string $search, ?string $status, ?string $type, ?int $resID, ?string $date, int $perPage): LengthAwarePaginator;

    /** Sessions still running that started before today. */
    public function staleActive(): Collection;

    /** Every session still running. */
    public function allActive(): Collection;

    public function hasActiveSessionForStudent(int $studentID): bool;

    /** The session the student is in right now (not yet ended), with its resource, or null. */
    public function activeForStudent(int $studentID): ?\App\Models\ResourceUsageLog;
}
