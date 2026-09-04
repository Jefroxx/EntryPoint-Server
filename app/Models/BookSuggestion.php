<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookSuggestion extends Model
{
    use HasFactory;

    protected $primaryKey = 'suggestionID';

    protected $fillable = [
        'uuid', 'studentID', 'reviewedByLibrarianID', 'title', 'author',
        'reason', 'status', 'progressStep', 'submittedAt',
    ];

    protected $casts = ['submittedAt' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(Librarian::class, 'reviewedByLibrarianID', 'librarianID');
    }
}
