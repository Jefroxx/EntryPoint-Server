<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointRedemption extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'redemptionID';

    protected $fillable = [
        'uuid', 'studentID', 'itemID', 'quantity', 'pointsSpent', 'fulfillmentStatus', 'redeemedAt',
    ];

    protected $casts = ['redeemedAt' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function item()
    {
        return $this->belongsTo(MarketItem::class, 'itemID', 'itemID');
    }
}
