<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Librarian extends Model
{
    use HasFactory;

    // Shared PK pattern: librarians.librarianID IS users.userID (class-table inheritance)
    protected $primaryKey = 'librarianID';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'librarianID',
        'uuid',
        'role',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'librarianID', 'userID');
    }

    public function reviewedStudents()
    {
        return $this->hasMany(Student::class, 'reviewedByLibrarianID', 'librarianID');
    }

    public function verifiedSelfReturnReports()
    {
        return $this->hasMany(SelfReturnReport::class, 'verifiedByLibrarianID', 'librarianID');
    }

    public function reviewedBookSuggestions()
    {
        return $this->hasMany(BookSuggestion::class, 'reviewedByLibrarianID', 'librarianID');
    }

    public function staffedResourceUsageLogs()
    {
        return $this->hasMany(ResourceUsageLog::class, 'staffLibrarianID', 'librarianID');
    }
}
