<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One area of the library: its name, the acronym printed on the spine label, and how it is lent out.
 * (The table keeps its first name; the lending rule it began with is still what the circulation desk reads.)
 * `area` is the key that books and loans store.
 */
class LoanPeriod extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'area';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['area', 'uuid', 'name', 'acronym', 'loanable', 'periodValue', 'periodUnit', 'sortOrder'];

    protected $casts = [
        'loanable'    => 'boolean',
        'periodValue' => 'integer',
        'sortOrder'   => 'integer',
    ];

    /** An area made without the extras (a test, a seeder) still gets an id, a name and an acronym from its key. */
    protected static function booted(): void
    {
        static::creating(function (self $area) {
            $area->uuid ??= (string) \Illuminate\Support\Str::uuid();
            $area->name ??= \Illuminate\Support\Str::headline($area->area);
            $area->acronym ??= strtoupper(\Illuminate\Support\Str::substr($area->area, 0, 3));
        });
    }

    public function books()
    {
        return $this->hasMany(Book::class, 'areaOfLibrary', 'area');
    }
}
