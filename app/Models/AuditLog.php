<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing a staff account did, copied in with the actor's name and role as they were at the time. */
class AuditLog extends Model
{
    protected $primaryKey = 'logID';

    // Only a created_at, set by the database; entries are never edited.
    public $timestamps = false;

    protected $fillable = ['actorID', 'actorName', 'actorRole', 'action', 'summary'];

    protected $casts = ['created_at' => 'datetime'];
}
