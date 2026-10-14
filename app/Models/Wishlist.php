<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'wishlistID';

    protected $fillable = [
        'uuid',
        'studentID',
        'bookID',
        'inCart',
        'inWishlist',
        'addedAt',
    ];

    protected $casts = [
        'addedAt'    => 'datetime',
        'inCart'     => 'boolean',
        'inWishlist' => 'boolean',
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
