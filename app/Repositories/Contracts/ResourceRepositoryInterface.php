<?php

namespace App\Repositories\Contracts;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Collection;

interface ResourceRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?string $resourceType, ?string $status): Collection;

    public function studentListWithFilters(?string $resourceType): Collection;

    public function lockForUpdate(int $resID): Resource;
}
