<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Repositories\Contracts\LibrarianRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private LibrarianRepositoryInterface $librarians,
    ) {
    }

    public function send(int $userID, string $message, ?string $type = null): SystemNotification
    {
        return $this->notifications->create([
            'uuid'    => Str::uuid(),
            'userID'  => $userID,
            'message' => $message,
            'type'    => $type,
            'sentAt'  => now(),
            'isRead'  => false,
        ]);
    }

    /**
     * Broadcasts one notification per librarian. Replaces the
     * `Librarian::all()->each(...)` pattern that was duplicated across
     * five controllers.
     */
    public function notifyAllLibrarians(string $message, string $type, ?int $exceptLibrarianID = null): void
    {
        $librarians = $exceptLibrarianID
            ? $this->librarians->allExcept($exceptLibrarianID)
            : $this->librarians->all();

        foreach ($librarians as $librarian) {
            $this->send($librarian->librarianID, $message, $type);
        }
    }

    public function listForUser(int $userID): array
    {
        $notifications = $this->notifications->forUser($userID);

        return [
            'notifications' => $notifications,
            'unreadCount'   => $notifications->where('isRead', false)->count(),
        ];
    }

    public function markAsRead(SystemNotification $notification, int $userID): void
    {
        if ($notification->userID !== $userID) {
            throw new AuthorizationException('This notification does not belong to you.');
        }

        $this->notifications->update($notification, ['isRead' => true]);
    }

    public function markAllAsRead(int $userID): void
    {
        $this->notifications->markAllReadForUser($userID);
    }
}
