<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'bookID';

    protected $fillable = [
        'uuid',
        'subjectID',
        'areasOfLibrary',
        'title',
        'classNumber',
        'isbn',
        'publicationYear',
        'volume',
        'edition',
        'pages',
        'publisher',
        'sourceOfFund',
        'cost',
        'copyNumber',
        'remarks',
        'coverImageURL',
        'shelfLocation',
    ];

    public function subject()
    {
        return $this->belongsTo(BookSubject::class, 'subjectID', 'subjectID');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'bookID', 'bookID');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'book_author', 'bookID', 'authorID')
            ->withPivot('role');
    }
}
