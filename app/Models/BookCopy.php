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

    /**
     * Accession numbers run 1, 2, 3... A copy saved without one takes the next number, assigned here
     * right before its insert, so the Add Book flow, seeders and factories all number copies the same way.
     * Inside a transaction, FOR UPDATE holds the top of the index until commit, so two librarians adding
     * copies at the same moment can't both take the same number.
     */
    protected static function booted(): void
    {
        static::creating(function (BookCopy $copy) {
            $copy->accessionNumber ??= (int) static::query()->lockForUpdate()->max('accessionNumber') + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'accessionNumber' => 'integer',
        ];
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }
}
