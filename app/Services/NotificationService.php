<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Repositories\Contracts\LibrarianRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Which staff area (config/permissions.php) each desk notice belongs to. A notice only goes to staff whose role
     * has that area, so a student assistant isn't told about book suggestions they can't open. A type missing here
     * goes to every non-admin staff account.
     */
    private const AREA_FOR_TYPE = [
        'student_checked_in'   => 'attendance',
        'loan_checked_out'     => 'circulation',
        'self_return_reported' => 'circulation',
        'new_reservation'      => 'circulation',
        'copy_penalty_choice'  => 'circulation',
        'new_book_suggestion'  => 'library',
        'new_registration'     => 'students',
        'new_redemption'       => 'engagement',
    ];

    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private LibrarianRepositoryInterface $librarians,
        private StudentRepositoryInterface $students,
        private StaffPermissionService $permissions,
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
     * Broadcasts a desk notice to the staff who can act on it: never the administrator (the admin side has no desk
     * pages, so its Review/Verify buttons led nowhere), and only roles that have the notice's area (AREA_FOR_TYPE).
     */
    public function notifyAllLibrarians(string $message, string $type, ?int $exceptLibrarianID = null): void
    {
        $librarians = $exceptLibrarianID
            ? $this->librarians->allExcept($exceptLibrarianID)
            : $this->librarians->all();

        $area = self::AREA_FOR_TYPE[$type] ?? null;
        $allowed = []; // role => may receive it, worked out once per role

        foreach ($librarians as $librarian) {
            $role = $librarian->role ?? 'librarian';
            $allowed[$role] ??= $role !== StaffPermissionService::ADMIN
                && ($area === null || in_array($area, $this->permissions->forRole($role), true));

            if ($allowed[$role]) {
                $this->send($librarian->librarianID, $message, $type);
            }
        }
    }

    /** One notification for every approved member (students, faculty and staff). */
    public function notifyAllStudents(string $message, string $type): void
    {
        foreach ($this->students->approvedIDs() as $studentID) {
            $this->send($studentID, $message, $type);
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
