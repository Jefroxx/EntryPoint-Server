<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs an account out after it has sat unused for a while (config `sanctum.idle_timeout`, 4 hours by default).
 * Without this a sign-in lived for the whole token lifetime (14 days), so opening the site the next morning was
 * still signed in.
 *
 * "Used" means a request the person caused. The student app also pings `/api/session` every few seconds while a tab
 * is open, so that ping does not count as use, or a forgotten tab would keep the sign-in alive forever. (Sanctum's own
 * `last_used_at` can't be used for this: that ping refreshes it too.)
 *
 * When there is no record yet (first request after this was deployed, or the cache was cleared) the sign-in is given
 * a fresh start instead of being cut off.
 */
class EndIdleSessions
{
    public const ACTIVITY_PREFIX = 'token-last-activity:';
    public const IDLED_PREFIX = 'token-idled:';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $key = self::ACTIVITY_PREFIX.$token->getKey();
        $last = Cache::get($key);
        $idleSeconds = (int) config('sanctum.idle_timeout', 240) * 60;

        if ($last !== null && now()->timestamp - (int) $last > $idleSeconds) {
            $token->delete();
            Cache::forget($key);
            // bootstrap/app.php reads this to answer with reason "idle" instead of a plain 401.
            Cache::put(self::IDLED_PREFIX.$token->getKey(), true, now()->addDay());

            throw new AuthenticationException('You were signed out because you were inactive for a while.');
        }

        if ($last === null || ! $request->is('api/session')) {
            Cache::put($key, now()->timestamp, now()->addDays(15));
        }

        return $next($request);
    }
}
