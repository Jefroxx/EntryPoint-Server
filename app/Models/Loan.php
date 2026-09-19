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
        'dueSoonNotifiedAt', 'overdueNotifiedAt',
    ];

    protected $casts = [
        'checkoutDate'      => 'datetime',
        'dueDate'           => 'datetime',
        'returnDate'        => 'datetime',
        'dueSoonNotifiedAt' => 'datetime',
        'overdueNotifiedAt' => 'datetime',
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
}
