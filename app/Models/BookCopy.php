<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public static function generateUniqueBarcode(): string
    {
        do {
            $code = 'BK-' . strtoupper(Str::random(10));
        } while (self::where('barcodeValue', $code)->exists());

        return $code;
    }
}
