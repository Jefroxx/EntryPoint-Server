<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'logID';

    protected $fillable = ['uuid', 'studentID', 'visitorName', 'visitorPurpose', 'entryTime', 'exitTime'];

    protected $casts = [
        'entryTime' => 'datetime',
        'exitTime' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }
}
