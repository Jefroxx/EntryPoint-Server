<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketCartItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'cartItemID';

    protected $fillable = ['uuid', 'studentID', 'itemID', 'quantity'];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function item()
    {
        return $this->belongsTo(MarketItem::class, 'itemID', 'itemID');
    }
}
