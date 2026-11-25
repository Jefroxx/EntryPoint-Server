<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

/** A kind of resource (Computer, Study Room, Projector...): picked from this list, never typed. */
class ResourceType extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'typeID';

    protected $fillable = ['uuid', 'name'];

    /** The resources of this type (a resource keeps its type's name). */
    public function resources()
    {
        return $this->hasMany(Resource::class, 'resourceType', 'name');
    }
}
