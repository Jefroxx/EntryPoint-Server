<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A librarian's phone, used as an alternative camera for the desk.
 *
 * The desk (Checkout, Start Session, Reservations) shows a QR code. Opening it on the phone swaps a single-use code for
 * a limited sign-in that can do exactly one thing: send barcodes it reads. Each barcode goes into a short queue kept for
 * that librarian, and the desk, which polls the queue, handles it as if its own camera had read it.
 *
 * Nothing here is stored in the database but the phone's sign-in token (so it can be revoked): the code and the queue
 * are short-lived cache entries, which is right for something that only matters for a few minutes.
 */
class PhoneScannerService
{
    /** The name and the one ability of the limited sign-in the phone gets. */
    public const TOKEN_NAME = 'phone-scanner';
    public const ABILITY = 'scan-relay';

    private const PAIRING_MINUTES = 10;
    private const TOKEN_HOURS = 12;
    private const QUEUE_MINUTES = 30;
    private const QUEUE_LIMIT = 50;
    /** A barcode nobody picked up within this long is dropped. */
    private const STALE_SECONDS = 15;
    /** After a Checkout or Start Session window asks, the Station stands back for this long. */
    private const DESK_PRIORITY_SECONDS = 5;
    /** A phone counts as connected for this long after it last said something. */
    private const PRESENCE_SECONDS = 15;

    /** A one-time code for the QR. Whoever opens it first, within ten minutes, gets the phone sign-in. */
    public function createPairing(User $librarian): array
    {
        $code = Str::random(40);
        Cache::put($this->pairingKey($code), $librarian->userID, now()->addMinutes(self::PAIRING_MINUTES));

        return ['code' => $code, 'expiresAt' => now()->addMinutes(self::PAIRING_MINUTES)->toIso8601String()];
    }

    /** Trades the code for the phone's limited token. The code works once. */
    public function claim(string $code): array
    {
        $userID = Cache::pull($this->pairingKey($code));
        $user = $userID ? User::find($userID) : null;

        if (! $user || $user->userType !== 'librarian' || $user->disabledAt) {
            throw ValidationException::withMessages([
                'code' => ['This link has expired or was already used. Show a new one on the computer.'],
            ]);
        }

        $expires = now()->addHours(self::TOKEN_HOURS);
        $token = $user->createToken(self::TOKEN_NAME, [self::ABILITY], $expires);
        $this->touch($user);

        return [
            'token'     => $token->plainTextToken,
            'expiresAt' => $expires->toIso8601String(),
            'name'      => $user->fullName,
        ];
    }

    /** The phone says it is still there (also called by every scan). */
    public function touch(User $user): void
    {
        Cache::put($this->seenKey($user), now()->timestamp, now()->addMinutes(2));
    }

    /** Puts one barcode the phone read into the librarian's queue. */
    public function push(User $user, string $code): void
    {
        $this->touch($user);

        Cache::lock($this->lockKey($user), 3)->block(2, function () use ($user, $code) {
            $queue = Cache::get($this->queueKey($user), []);
            $queue[] = ['code' => $code, 'at' => now()->timestamp];

            // Only the newest few are kept: the desk reads the queue every second or so.
            Cache::put($this->queueKey($user), array_slice($queue, -self::QUEUE_LIMIT), now()->addMinutes(self::QUEUE_MINUTES));
        });
    }

    /**
     * What a desk window asks every second: the barcodes the phone has sent since the last ask, and whether a phone is
     * connected. Asking TAKES them: each barcode is handed to exactly one asker, so two tabs open on the same account
     * can never both act on one scan (for attendance, that checks a student in and straight back out).
     *
     * `$as` says who is asking. A Checkout or Start Session window ("desk") comes before the Station ("station"): while
     * one of them has asked in the last few seconds, the Station is given nothing and the barcodes wait for the window.
     * Barcodes older than a few seconds are dropped, so a window opened later never acts on something scanned long ago.
     *
     * @return array{scans: array<int, array{code: string, at: int}>, phoneConnected: bool}
     */
    public function take(User $user, string $as = 'desk'): array
    {
        $connected = $this->connected($user);

        if ($as === 'desk') {
            Cache::put($this->deskKey($user), now()->timestamp, now()->addSeconds(self::DESK_PRIORITY_SECONDS));
        } elseif (Cache::has($this->deskKey($user))) {
            return ['scans' => [], 'phoneConnected' => $connected];
        }

        $queue = Cache::lock($this->lockKey($user), 3)->block(2, function () use ($user) {
            $queue = Cache::get($this->queueKey($user), []);
            Cache::forget($this->queueKey($user));

            return $queue;
        });

        $cutoff = now()->subSeconds(self::STALE_SECONDS)->timestamp;
        $fresh = array_values(array_filter($queue, fn (array $scan) => $scan['at'] >= $cutoff));

        return ['scans' => $fresh, 'phoneConnected' => $connected];
    }

    public function connected(User $user): bool
    {
        $seen = Cache::get($this->seenKey($user));

        return $seen !== null && now()->timestamp - (int) $seen <= self::PRESENCE_SECONDS
            && $user->tokens()->where('name', self::TOKEN_NAME)->exists();
    }

    /** Signs every phone of this librarian out and empties the queue. */
    public function disconnect(User $user): void
    {
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();
        Cache::forget($this->seenKey($user));
        Cache::forget($this->queueKey($user));
    }

    private function pairingKey(string $code): string { return 'phone-pair:' . hash('sha256', $code); }
    private function seenKey(User $user): string { return "phone-seen:{$user->userID}"; }
    private function queueKey(User $user): string { return "scan-relay:{$user->userID}"; }
    private function deskKey(User $user): string { return "scan-relay-desk:{$user->userID}"; }
    private function lockKey(User $user): string { return "scan-relay-lock:{$user->userID}"; }
}
