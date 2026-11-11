<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'logID';

    protected $fillable = ['uuid', 'studentID', 'visitorName', 'visitorPurpose', 'entryTime', 'checkedInBy', 'exitTime', 'checkedOutBy'];

    protected $casts = [
        'entryTime' => 'datetime',
        'exitTime' => 'datetime',
    ];

    /** The account rows themselves never go out in JSON; see withStaff() for what the staff screens get instead. */
    protected $hidden = ['checkedInByUser', 'checkedOutByUser'];

    /**
     * Adds who scanned the visit in and out, as a small summary (`checkedInByStaff`, `checkedOutByStaff`: name and
     * role), to this visit's JSON. Opt-in on purpose: the student app reads these same visits, and a student is not
     * shown which staff member scanned them. Only the staff screens call this.
     */
    public function withStaff(): static
    {
        return $this->loadMissing(['checkedInByUser.librarian', 'checkedOutByUser.librarian'])
            ->append(['checkedInByStaff', 'checkedOutByStaff']);
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    /** The staff account that scanned this visit in; null for visits logged before this was kept. */
    public function checkedInByUser()
    {
        return $this->belongsTo(User::class, 'checkedInBy', 'userID');
    }

    /** The staff account that scanned it out; null while it is open, or when the system closed it (nightly clean-up, end of day). */
    public function checkedOutByUser()
    {
        return $this->belongsTo(User::class, 'checkedOutBy', 'userID');
    }

    /** @return array{name: string, role: string}|null */
    public function getCheckedInByStaffAttribute(): ?array
    {
        return self::staffSummary($this->checkedInByUser);
    }

    /** @return array{name: string, role: string}|null */
    public function getCheckedOutByStaffAttribute(): ?array
    {
        return self::staffSummary($this->checkedOutByUser);
    }

    /** Name and role ("admin", "librarian" or "assistant") of the account, or null when nobody is recorded. */
    private static function staffSummary(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'name' => $user->fullName,
            'role' => $user->librarian?->role ?? ($user->userType === 'admin' ? 'admin' : 'librarian'),
        ];
    }
}
