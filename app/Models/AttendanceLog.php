<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'logID';

    protected $fillable = ['uuid', 'studentID', 'entryTime', 'exitTime'];

    protected $casts = [
        'entryTime' => 'datetime',
        'exitTime' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }
}
