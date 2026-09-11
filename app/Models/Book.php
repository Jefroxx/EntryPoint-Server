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
        'categoryID',
        'title',
        'callNumber',
        'isbn',
        'publicationYear',
        'coverImageURL',
        'shelfLocation',
    ];

    protected $casts = [
        'publicationYear' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(BookCategory::class, 'categoryID', 'categoryID');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'book_author', 'bookID', 'authorID')
            ->withPivot('role');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'bookID', 'bookID');
    }

    public function totalCopiesCount(): int
    {
        return $this->copies()->where('status', '!=', 'retired')->count();
    }

    public function availableCopiesCount(): int
    {
        return $this->copies()->where('status', 'available')->count();
    }

    public function isAvailable(): bool
    {
        return $this->availableCopiesCount() > 0;
    }
}
