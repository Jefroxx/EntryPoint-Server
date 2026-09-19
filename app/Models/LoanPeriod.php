<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPeriod extends Model
{
    protected $primaryKey = 'area';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['area', 'loanable', 'periodValue', 'periodUnit'];

    protected $casts = [
        'loanable'    => 'boolean',
        'periodValue' => 'integer',
    ];
}
