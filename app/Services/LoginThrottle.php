<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Stops password guessing. Nothing limited sign-in before: anyone could try passwords against an account as fast as the
 * server answered. Two counters, both of FAILED sign-ins and both forgotten after the window:
 *
 *   - this account from this address: 5 failures, so a person who mistypes is locked out for a few minutes, not forever;
 *   - one account from anywhere: 20, which catches a guesser who keeps changing address.
 *
 * Deliberately NOT a limit per address on its own. On the hosted site the browser reaches the API through the Pages
 * proxy, so every visitor arrives from the same address; a per-address limit would be one bucket for the whole school,
 * and one person's bad guesses could lock everyone out. Counting per account does not have that problem.
 *
 * Every failure counts, whether or not the account exists, so the lock never tells an attacker which accounts are real.
 * A successful sign-in clears the account-and-address counter. A lock is a 429 shown in the same place as any other
 * sign-in problem, and is written to the audit log (area "security") when it starts.
 */
class LoginThrottle
{
    public const PER_ACCOUNT_AND_ADDRESS = 5;
    public const PER_ACCOUNT = 20;

    /** Seconds a counter lives, and so how long a lock lasts at most. */
    public const WINDOW = 600;

    public function __construct(private AuditLogService $audit)
    {
    }

    /** Refuses the attempt (429) when any counter is full, before the password is even looked at. */
    public function ensureNotLocked(string $login): void
    {
        $wait = 0;

        foreach ($this->limits($login) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);

            throw ValidationException::withMessages([
                'email' => ["Too many sign-in attempts. Please wait {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' and try again.'],
            ])->status(429);
        }
    }

    /** One more failed sign-in. The moment an account-and-address pair fills up, the lock is written to the audit log. */
    public function fail(string $login): void
    {
        foreach ($this->limits($login) as [$key, $max]) {
            RateLimiter::hit($key, self::WINDOW);

            if ($max === self::PER_ACCOUNT_AND_ADDRESS && RateLimiter::attempts($key) === $max) {
                $this->audit->record(
                    null,
                    'security.login_locked',
                    mb_substr('Sign-in locked for '.(self::WINDOW / 60).' minutes after '.$max." failed attempts: \"{$this->normalise($login)}\" from ".request()->ip(), 0, 255),
                );
            }
        }
    }

    /** A correct password: this person's own failures are forgotten. */
    public function clear(string $login): void
    {
        RateLimiter::clear($this->limits($login)[0][0]);
    }

    /** @return array<int, array{0: string, 1: int}> */
    private function limits(string $login): array
    {
        $account = sha1($this->normalise($login));
        $address = (string) request()->ip();

        return [
            ["login:account-address:{$account}|{$address}", self::PER_ACCOUNT_AND_ADDRESS],
            ["login:account:{$account}", self::PER_ACCOUNT],
        ];
    }

    private function normalise(string $login): string
    {
        return mb_strtolower(trim($login));
    }
}
