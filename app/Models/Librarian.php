<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Librarian extends Model
{
    use HasFactory, UsesUuidRouteKey;

    // Shared PK pattern: librarians.librarianID IS users.userID (class-table inheritance)
    protected $primaryKey = 'librarianID';
    public $incrementing = false;
    protected $keyType = 'int';    /** Staff roles: the administrator, a librarian, or a student assistant at the desk (see config/permissions.php). */
    public const ROLES = ['admin', 'librarian', 'assistant'];

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
