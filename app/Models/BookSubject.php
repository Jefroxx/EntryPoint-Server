<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookSubject extends Model
{
    use HasFactory;

    protected $primaryKey = 'subjectID';

    protected $fillable = ['uuid', 'name', 'classificationCode'];

    public function books()
    {
        return $this->hasMany(Book::class, 'subjectID', 'subjectID');
    }
}
