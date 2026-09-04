<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasFactory;

    protected $primaryKey = 'penaltyID';

    protected $fillable = [
        'uuid', 'loanID', 'penaltyTypeID', 'amount',
        'computedAt', 'settledAt', 'paymentStatus',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'computedAt' => 'datetime',
        'settledAt' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loanID', 'loanID');
    }

    public function penaltyType()
    {
        return $this->belongsTo(PenaltyType::class, 'penaltyTypeID', 'penaltyTypeID');
    }
}
