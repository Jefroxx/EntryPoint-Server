<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    use HasFactory;

    protected $primaryKey = 'authorID';

    protected $fillable = ['uuid', 'name'];

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_author', 'authorID', 'bookID')
            ->withPivot('role');
    }
}
