<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'announcementID';

    protected $fillable = ['uuid', 'title', 'body', 'audience', 'expiresAt', 'createdByID'];

    protected $casts = ['expiresAt' => 'datetime'];

    /** Not past its end date (a notice with no end date never expires). */
    public function scopeActive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expiresAt')->orWhere('expiresAt', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expiresAt === null || $this->expiresAt->isFuture();
    }
}
