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

    /**
     * The library session: set when a librarian opens the scan station, cleared by its End session. It stays set while
     * the station tab is closed, so every screen can show the library is open until someone ends the day.
     * (Not a Settings value: the station keeps it, under the same key/value table.)
     */
    public const STATION_STARTED_AT = 'station_session_started_at';
    public const STATION_STARTED_BY = 'station_session_started_by';

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

    public static function string(string $key): ?string
    {
        $value = DB::table('app_settings')->where('key', $key)->value('value');

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** The open library session ({startedAt, startedBy}), or null when the station was ended (or never started). */
    public static function stationSession(): ?array
    {
        $startedAt = self::string(self::STATION_STARTED_AT);

        return $startedAt ? ['startedAt' => $startedAt, 'startedBy' => self::string(self::STATION_STARTED_BY)] : null;
    }

    /** Opens the library session. Opening the station again (another tab, a reload) keeps the time it first opened. */
    public static function startStationSession(string $startedBy): array
    {
        if (! self::stationSession()) {
            self::set(self::STATION_STARTED_AT, now()->toIso8601String());
            self::set(self::STATION_STARTED_BY, $startedBy);
        }

        return self::stationSession();
    }

    public static function endStationSession(): void
    {
        self::set(self::STATION_STARTED_AT, '');
        self::set(self::STATION_STARTED_BY, '');
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
