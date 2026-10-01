<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'itemID';

    protected $fillable = ['uuid', 'name', 'type', 'pointCost', 'stock', 'photoPath'];

    /** The stored path stays server-side; every response (shop, cart, redemptions) carries the full link instead. */
    protected $hidden = ['photoPath'];

    protected $appends = ['photoURL'];

    public function redemptions()
    {
        return $this->hasMany(PointRedemption::class, 'itemID', 'itemID');
    }

    /** Built from the request's own address, so the link works whatever APP_URL says. */
    public function getPhotoURLAttribute(): ?string
    {
        return $this->photoPath ? asset('storage/' . $this->photoPath) : null;
    }
}
