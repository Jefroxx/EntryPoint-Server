<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCategory extends Model
{
    use HasFactory;

    protected $primaryKey = 'categoryID';

    protected $fillable = ['uuid', 'name'];

    public function books()
    {
        return $this->hasMany(Book::class, 'categoryID', 'categoryID');
    }
}
