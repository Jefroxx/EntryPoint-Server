<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

/** A kind of reward (Merchandise, Voucher...): picked from this list, never typed. */
class MarketItemType extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'typeID';

    protected $fillable = ['uuid', 'name'];

    /** The rewards of this type (a reward keeps its type's name). */
    public function items()
    {
        return $this->hasMany(MarketItem::class, 'type', 'name');
    }
}
