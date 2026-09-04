<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'itemID';

    protected $fillable = ['uuid', 'name', 'type', 'pointCost', 'stock'];

    public function redemptions()
    {
        return $this->hasMany(PointRedemption::class, 'itemID', 'itemID');
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }
}
