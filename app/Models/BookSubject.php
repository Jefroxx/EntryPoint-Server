<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookSubject extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'subjectID';

    protected $fillable = ['uuid', 'name', 'classificationCode'];

    public function books()
    {
        return $this->hasMany(Book::class, 'subjectID', 'subjectID');
    }
}
