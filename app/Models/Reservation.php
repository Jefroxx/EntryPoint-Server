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

    protected $appends = ['pickupCode'];

    /**
     * The code printed as a barcode on the student's pickup slip. Derived from the reservation number
     * (like the loan receipt's L-000123), so there's nothing extra to store. The librarian scans or
     * types it at checkout; it only looks the reservation up, and checkout still needs a librarian.
     */
    public function getPickupCodeAttribute(): string
    {
        return 'R-' . str_pad((string) $this->reservationID, 6, '0', STR_PAD_LEFT);
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }
}
