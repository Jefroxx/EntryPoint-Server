<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** The web address of an uploaded picture, wherever the "public" disk keeps it. */
class Uploads
{
    public static function url(string $path): string
    {
        // A bucket (Cloudflare R2) has its own public address; the server's own disk is served under /storage.
        // The local one is built from the request's own address, so the link works whatever APP_URL says.
        if (config('filesystems.disks.public.driver') === 's3') {
            return Storage::disk('public')->url($path);
        }

        return asset('storage/' . $path);
    }
}
