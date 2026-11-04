<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a charge is for. `kind` is overdue / lost / damaged; `areaOfLibrary` says whose fine rule an overdue fee
 * follows (a lost or damaged copy has no area and no rule: the amount is the book's price).
 */
class PenaltyType extends Model
{
    use HasFactory;

    protected $primaryKey = 'penaltyTypeID';

    protected $fillable = ['uuid', 'kind', 'areaOfLibrary'];

    protected $appends = ['label'];

    /** "Overdue (circulation)", "Lost", "Damaged": a short reason for screens and receipts. */
    public function getLabelAttribute(): string
    {
        return $this->kind === 'overdue' && $this->areaOfLibrary
            ? "Overdue ({$this->areaOfLibrary})"
            : ucfirst($this->kind);
    }

    public function rules()
    {
        return $this->hasMany(PenaltyRule::class, 'penaltyTypeID', 'penaltyTypeID');
    }

    public function penalties()
    {
        return $this->hasMany(Penalty::class, 'penaltyTypeID', 'penaltyTypeID');
    }
}
