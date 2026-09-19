<?php

namespace App\Repositories\Eloquent;

use App\Models\SystemNotification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentNotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    public function __construct(SystemNotification $model)
    {
        parent::__construct($model);
    }

    public function forUser(int $userID): Collection
    {
        return SystemNotification::where('userID', $userID)
            ->orderByDesc('sentAt')
            ->get();
    }

    public function markAllReadForUser(int $userID): void
    {
        SystemNotification::where('userID', $userID)
            ->where('isRead', false)
            ->update(['isRead' => true]);
    }
}
