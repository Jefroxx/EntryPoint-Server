<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceUsageLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'usageID';

    protected $fillable = [
        'uuid', 'resID', 'studentID', 'staffLibrarianID', 'startTime', 'endTime',
    ];

    protected $casts = [
        'startTime' => 'datetime',
        'endTime' => 'datetime',
    ];

    public function resource()
    {
        return $this->belongsTo(Resource::class, 'resID', 'resID');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function staffLibrarian()
    {
        return $this->belongsTo(Librarian::class, 'staffLibrarianID', 'librarianID');
    }
}
