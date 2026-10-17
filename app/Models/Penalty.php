<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'penaltyID';

    /**
     * A fine becomes payable once its book is back. Still unpaid this many days after the return, it is flagged
     * as long unpaid in the Penalties tab.
     */
    public const LONG_UNPAID_DAYS = 30;

    protected $fillable = [
        'uuid', 'loanID', 'penaltyTypeID', 'amount',
        'computedAt', 'settledAt', 'paymentStatus', 'receiptNumber',
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
