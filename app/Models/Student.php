<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory, UsesUuidRouteKey;

    // Shared PK pattern: students.studentID IS users.userID (class-table inheritance)
    protected $primaryKey = 'studentID';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'studentID', // Explicitly included because of the 1:1 user mapping
        'uuid',
        'memberIDNumber',
        'memberType',
        'barcodeValue',
        'academicProgram',
        'department',
        'knowledgeScore',
        'visitStreak',
        'registrationStatus',
        'reviewedByLibrarianID',
        'reviewedAt',
        'rejectionReason',
    ];

    protected $casts = [
        'reviewedAt' => 'datetime',
    ];

    /** Faculty and staff borrow like students, but only students take part in the Hall of Fame, achievements and points shop. */
    public const MEMBER_TYPES = ['student', 'faculty', 'staff'];

    public function takesPartInEngagement(): bool
    {
        return $this->memberType === 'student';
    }

    /**
     * The visit streak as of today. The stored number is only moved at a check-in, so left alone it would keep saying
     * "8 days in a row" for someone who last came a month ago: it only counts while the last visit was today or yesterday.
     */
    public function currentVisitStreak(): int
    {
        if ((int) $this->visitStreak <= 0) {
            return 0;
        }

        $last = $this->attendanceLogs()->max('entryTime');

        return $last && \Illuminate\Support\Carbon::parse($last)->startOfDay()->gte(today()->subDay()) ? (int) $this->visitStreak : 0;
    }

    public function scopeStudentsOnly($query)
    {
        return $query->where('students.memberType', 'student');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'studentID', 'userID');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(Librarian::class, 'reviewedByLibrarianID', 'librarianID');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class, 'studentID', 'studentID');
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class, 'studentID', 'studentID');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'studentID', 'studentID');
    }

    public function loans()
    {
        return $this->hasMany(Loan::class, 'studentID', 'studentID');
    }

    public function bookSuggestions()
    {
        return $this->hasMany(BookSuggestion::class, 'studentID', 'studentID');
    }

    public function achievements()
    {
        // Don't forget to update your pivot columns to camelCase here!
        return $this->belongsToMany(Achievement::class, 'student_achievement', 'studentID', 'achievementID')
            ->withPivot(['triggerEvent', 'earnedAt', 'redeemedAt']);
    }

    public function pointRedemptions()
    {
        return $this->hasMany(PointRedemption::class, 'studentID', 'studentID');
    }

    public function marketCartItems()
    {
        return $this->hasMany(MarketCartItem::class, 'studentID', 'studentID');
    }

    public function resourceUsageLogs()
    {
        return $this->hasMany(ResourceUsageLog::class, 'studentID', 'studentID');
    }

    public function isApproved(): bool
    {
        return $this->registrationStatus === 'approved';
    }
}
