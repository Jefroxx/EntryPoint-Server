<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

/** A place library money comes from; the "Source of fund" box suggests these. */
class FundSource extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'fundSourceID';

    protected $fillable = ['uuid', 'name', 'sortOrder'];
}
