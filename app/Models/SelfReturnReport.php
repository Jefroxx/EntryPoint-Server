<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelfReturnReport extends Model
{
    use HasFactory;

    protected $primaryKey = 'reportID';

    protected $fillable = [
        'uuid', 'loanID', 'verifiedByLibrarianID', 'reportedAt', 'verificationStatus',
    ];

    protected $casts = ['reportedAt' => 'datetime'];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loanID', 'loanID');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(Librarian::class, 'verifiedByLibrarianID', 'librarianID');
    }
}
