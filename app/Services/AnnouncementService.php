<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Notices from the administrator to the student side, the staff side, or both. The administrator manages them; both
 * sides only read the active ones meant for them.
 */
class AnnouncementService
{
    public const AUDIENCES = ['everyone', 'students', 'staff'];

    public function __construct(private AuditLogService $audit)
    {
    }

    /** Everything, newest first, for the admin screen. */
    public function all(): array
    {
        return Announcement::orderByDesc('announcementID')->get()->map(fn (Announcement $a) => $this->row($a))->all();
    }

    /**
     * The active notices for one side: 'students' (the student portal, which includes faculty and staff members) or
     * 'staff' (the librarian side). Notices for everyone are in both.
     */
    public function forSide(string $side, int $limit = 5): array
    {
        return Announcement::active()
            ->whereIn('audience', ['everyone', $side])
            ->orderByDesc('announcementID')
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $a) => $this->row($a))
            ->all();
    }

    public function create(User $actor, array $data): array
    {
        $announcement = Announcement::create([
            'uuid'        => Str::uuid(),
            'title'       => $data['title'],
            'body'        => $data['body'],
            'audience'    => $data['audience'],
            'expiresAt'   => $data['expiresAt'] ?? null,
            'createdByID' => $actor->userID,
        ]);

        $this->audit->record($actor, 'announcements.posted', "Posted an announcement: {$announcement->title}");

        return $this->row($announcement);
    }

    public function update(User $actor, Announcement $announcement, array $data): array
    {
        $announcement->update([
            'title'     => $data['title'],
            'body'      => $data['body'],
            'audience'  => $data['audience'],
            'expiresAt' => $data['expiresAt'] ?? null,
        ]);

        $this->audit->record($actor, 'announcements.edited', "Edited an announcement: {$announcement->title}");

        return $this->row($announcement->fresh());
    }

    public function delete(User $actor, Announcement $announcement): void
    {
        $announcement->delete();

        $this->audit->record($actor, 'announcements.removed', "Removed an announcement: {$announcement->title}");
    }

    private function row(Announcement $a): array
    {
        return [
            'uuid'      => $a->uuid,
            'title'     => $a->title,
            'body'      => $a->body,
            'audience'  => $a->audience,
            'expiresAt' => $a->expiresAt?->toIso8601String(),
            'isActive'  => $a->isActive(),
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
