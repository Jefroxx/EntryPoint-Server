<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use HasFactory;

    protected $table = 'resources';
    protected $primaryKey = 'resID';

    protected $fillable = ['uuid', 'resourceType', 'name', 'status'];

    public const STATUSES = ['Available', 'In Use', 'Unavailable'];

    public function usageLogs()
    {
        return $this->hasMany(ResourceUsageLog::class, 'resID', 'resID');
    }

    /**
     * The currently open (endTime null) usage session for this resource,
     * if any — lets the librarian dashboard show who's using what without
     * a separate query per resource.
     */
    public function activeUsage()
    {
        return $this->hasOne(ResourceUsageLog::class, 'resID', 'resID')
            ->whereNull('endTime')
            ->latestOfMany('startTime');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'Available';
    }
}
