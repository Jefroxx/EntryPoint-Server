<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small key/value store for librarian-editable settings. Anything not stored
 * here falls back to the defaults in config/, so the app works before any
 * setting has been saved.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, string|int $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** Loan length in days for a collection (circulationType). */
    public static function dueDays(string $area): int
    {
        return (int) static::getValue("due_days.{$area}", (string) config("loans.due_days.{$area}", 7));
    }
}
