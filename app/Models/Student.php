<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Student extends Model
{
    use HasFactory;

    // Shared PK pattern: students.studentID IS users.userID (class-table inheritance)
    protected $primaryKey = 'studentID';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'studentID', // Explicitly included because of the 1:1 user mapping
        'uuid',
        'studentIDNumber',
        'barcodeValue',
        'academicProgram',
        'knowledgeScore',
        'visitStreak',
        'registrationStatus',
        'reviewedByLibrarianID',
        'reviewedAt',
    ];

    public static function generateUniqueBarcode(): string
    {
        do {
            $code = 'STI-' . strtoupper(Str::random(10));
        } while (self::where('barcodeValue', $code)->exists());

        return $code;
    }

    protected $casts = [
        'reviewedAt' => 'datetime',
    ];

    /**
     * Whenever a student's gamification metrics move, re-check achievement
     * criteria automatically — keeps the achievement engine decoupled from
     * whichever module (attendance, loans, etc.) actually earns the points.
     */
    protected static function booted(): void
    {
        static::updated(function (self $student) {
            if ($student->wasChanged(['knowledgeScore', 'visitStreak'])) {
                Achievement::evaluateForStudent($student);
            }
        });
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
