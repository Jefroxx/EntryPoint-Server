<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenaltyType extends Model
{
    use HasFactory;

    protected $primaryKey = 'penaltyTypeID';

    protected $fillable = ['uuid', 'category'];

    public function rules()
    {
        return $this->hasMany(PenaltyRule::class, 'penaltyTypeID', 'penaltyTypeID');
    }

    public function penalties()
    {
        return $this->hasMany(Penalty::class, 'penaltyTypeID', 'penaltyTypeID');
    }
}
