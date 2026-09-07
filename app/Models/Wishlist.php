<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $primaryKey = 'wishlistID';

    protected $fillable = [
        'uuid',
        'studentID',
        'bookID',
        'inCart',
        'addedAt',
    ];

    protected $casts = [
        'addedAt' => 'datetime',
        'inCart'  => 'boolean',
    ];
    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }
}
