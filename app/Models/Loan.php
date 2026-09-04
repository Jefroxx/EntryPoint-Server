<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $primaryKey = 'loanID';

    protected $fillable = [
        'uuid', 'studentID', 'bookID', 'loanType',
        'checkoutDate', 'dueDate', 'returnDate', 'status',
    ];

    protected $casts = [
        'checkoutDate' => 'datetime',
        'dueDate' => 'datetime',
        'returnDate' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
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
}
