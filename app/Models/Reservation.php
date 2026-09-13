<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $primaryKey = 'reservationID';

    protected $fillable = ['uuid', 'studentID', 'bookID', 'status', 'reservedAt'];

    protected $casts = ['reservedAt' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }

    /**
     * Notify the front-of-queue Waiting reservation for this book that a
     * copy has just become available. Doesn't change reservation status —
     * a librarian still confirms via ReservationController::accept().
     */
    public static function notifyNextInQueue(int $bookID): void
    {
        $hasAvailableCopy = BookCopy::where('bookID', $bookID)
            ->where('status', 'available')
            ->exists();

        if (! $hasAvailableCopy) {
            return;
        }

        $next = self::where('bookID', $bookID)
            ->where('status', 'Waiting')
            ->orderBy('reservedAt')
            ->with('book')
            ->first();

        if (! $next) {
            return;
        }

        SystemNotification::notify(
            $next->studentID,
            "A copy of \"{$next->book->title}\" is now available - it's your turn! Visit the library to claim it.",
            'reservation_turn'
        );
    }
}
