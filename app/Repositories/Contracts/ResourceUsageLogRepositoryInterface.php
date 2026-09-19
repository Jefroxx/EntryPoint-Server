<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ResourceUsageLogRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?int $resID, bool $activeOnly): Collection;

    public function hasActiveSessionForStudent(int $studentID): bool;
}
