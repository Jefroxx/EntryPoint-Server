<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $primaryKey = 'loanID';

    protected $fillable = [
        'uuid', 'studentID', 'copyID', 'loanType',
        'checkoutDate', 'dueDate', 'returnDate', 'status',
    ];

    protected $casts = [
        'checkoutDate' => 'datetime',
        'dueDate'      => 'datetime',
        'returnDate'   => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function copy()
    {
        return $this->belongsTo(BookCopy::class, 'copyID', 'copyID');
    }

    public function selfReturnReport()
    {
        return $this->hasOne(SelfReturnReport::class, 'loanID', 'loanID');
    }

    public function penalties()
    {
        return $this->hasMany(Penalty::class, 'loanID', 'loanID');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'Active' && $this->dueDate->isPast();
    }

    /**
     * Mark this loan as returned: frees the copy, closes the loan,
     * and does one final penalty accrual to lock in the total fine
     * (if any) as of the exact return moment.
     */
    public function markReturned(): void
    {
        $this->returnDate = now();
        $this->status = 'Returned';
        $this->save();

        $this->copy->update(['status' => 'available']);

        Penalty::accrueForLoan($this);
    }
}
