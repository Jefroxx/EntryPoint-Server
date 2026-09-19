<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface NotificationRepositoryInterface extends RepositoryInterface
{
    public function forUser(int $userID): Collection;

    public function markAllReadForUser(int $userID): void;
}
