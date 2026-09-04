<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    protected $primaryKey = 'bookID';

    protected $fillable = [
        'uuid',
        'categoryID',
        'title',
        'callNumber',
        'accessionNumber',
        'coverImageURL',
        'shelfLocation',
        'totalCopies',
        'availableCopies',
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

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class, 'bookID', 'bookID');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'bookID', 'bookID');
    }

    public function loans()
    {
        return $this->hasMany(Loan::class, 'bookID', 'bookID');
    }

    public function isAvailable(): bool
    {
        return $this->availableCopies > 0;
    }
}
