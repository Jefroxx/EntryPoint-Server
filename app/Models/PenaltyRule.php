<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenaltyRule extends Model
{
    use HasFactory;

    protected $primaryKey = 'ruleID';

    protected $fillable = ['uuid', 'penaltyTypeID', 'rate', 'gracePeriodDays'];

    protected $casts = ['rate' => 'decimal:2'];

    public function penaltyType()
    {
        return $this->belongsTo(PenaltyType::class, 'penaltyTypeID', 'penaltyTypeID');
    }
}
