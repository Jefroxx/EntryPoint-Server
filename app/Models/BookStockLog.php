<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookStockLog extends Model
{
    protected $table = 'book_stock_logs';
    protected $primaryKey = 'logID';

    protected $fillable = [
        'bookID',
        'bookTitle',
        'copyID',
        'accessionNumber',
        'action',
        'reason',
        'note',
        'librarianID',
        'librarianName',
    ];

    public const ACTIONS = ['added', 'removed'];

    /** Why a copy leaves the catalog. */
    public const REMOVE_REASONS = ['Lost', 'Damaged', 'Withdrawn', 'Donated', 'Other'];
}
