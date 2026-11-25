<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The few library-wide settings a librarian can change in Settings (a key and a value, kept in `app_settings`).
 * Anything not set yet has its default here, so the system works before anyone opens Settings.
 */
class AppSettings
{
    /** How many hours an accepted reservation holds a copy before it expires (24 = a day). */
    public const RESERVATION_HOLD_HOURS = 'reservation_hold_hours';

    /** The most books one student can have at a time: loans and open reservations together. */
    public const MAX_BOOKS = 'max_books_per_student';

    /** A reward with this many left, or fewer, counts as running low in the Rewards Market. */
    public const LOW_STOCK_AT = 'reward_low_stock_at';

    private const DEFAULTS = [
        self::RESERVATION_HOLD_HOURS => 24,
        self::MAX_BOOKS              => 3,
        self::LOW_STOCK_AT           => 3,
    ];

    public static function int(string $key): int
    {
        $value = DB::table('app_settings')->where('key', $key)->value('value');

        return $value !== null ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);
    }

    public static function set(string $key, int|string $value): void
    {
        DB::table('app_settings')->upsert(
            [['key' => $key, 'value' => (string) $value, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at']
        );
    }

    public static function holdHours(): int
    {
        return max(self::int(self::RESERVATION_HOLD_HOURS), 1);
    }

    public static function maxBooks(): int
    {
        return max(self::int(self::MAX_BOOKS), 1);
    }

    public static function lowStockAt(): int
    {
        return max(self::int(self::LOW_STOCK_AT), 0);
    }

    /** "1 day", "3 days", "36 hours", "1 hour": whole days when it is a whole number of them. */
    public static function describeHours(int $hours): string
    {
        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        return $hours.' '.($hours === 1 ? 'hour' : 'hours');
    }
}
