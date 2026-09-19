<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    use HasFactory;

    protected $primaryKey = 'copyID';

    protected $fillable = [
        'uuid',
        'bookID',
        'accessionNumber',
        'barcodeValue',
        'status',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }
}
