<?php

namespace App\Models\Concerns;

/**
 * Route-model binding resolves {loan}, {reservation} and the like by `uuid` instead of the
 * auto-increment key, so URLs carry something that can't be guessed by counting upwards.
 * Foreign keys and everything inside the database still use the integer ids.
 */
trait UsesUuidRouteKey
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
