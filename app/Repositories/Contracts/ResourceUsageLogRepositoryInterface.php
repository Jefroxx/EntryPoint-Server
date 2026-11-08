<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ResourceUsageLogRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?int $resID, bool $activeOnly): Collection;

    public function hasActiveSessionForStudent(int $studentID): bool;

    /** The session the student is in right now (not yet ended), with its resource, or null. */
    public function activeForStudent(int $studentID): ?\App\Models\ResourceUsageLog;
}
