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

    public function verify(Librarian $librarian): void
    {
        if ($this->verificationStatus !== 'Pending') {
            throw new \RuntimeException("Only a 'Pending' self-return report can be verified.");
        }

        $this->update([
            'verifiedByLibrarianID' => $librarian->librarianID,
            'verificationStatus'    => 'Verified',
        ]);

        $this->loan->markReturned();
    }

    public function reject(Librarian $librarian): void
    {
        if ($this->verificationStatus !== 'Pending') {
            throw new \RuntimeException("Only a 'Pending' self-return report can be rejected.");
        }

        $this->update([
            'verifiedByLibrarianID' => $librarian->librarianID,
            'verificationStatus'    => 'Rejected',
        ]);
    }
}
