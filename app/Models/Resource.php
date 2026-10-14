<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $table = 'resources';
    protected $primaryKey = 'resID';

    protected $fillable = ['uuid', 'resourceType', 'name', 'status'];

    public const STATUSES = ['Available', 'In Use', 'Unavailable'];

    protected $appends = ['barcodeValue'];

    /**
     * The code on the facility's printed label (F-000012). The universal scanner reads the prefix to know it is
     * a computer or study room, so scanning it starts or ends a session instead of an attendance check-in.
     */
    public function getBarcodeValueAttribute(): string
    {
        return 'F-' . str_pad((string) $this->resID, 6, '0', STR_PAD_LEFT);
    }

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
}
