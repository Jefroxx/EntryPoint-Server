<?php

namespace App\Support;

/**
 * The address students use to reach the site, worked out per request so nobody has to edit `.env`
 * every time the address changes (a quick tunnel gets a new one on every restart, and the real domain
 * isn't bought yet).
 *
 * The browser says where it is: every request it makes carries an `Origin` (or `Referer`). That is used
 * for links in emails, but only if the host is one we trust, so nobody can make the server mail out
 * links to a site of their choosing. Anything else falls back to `PORTAL_URL`.
 */
class PortalUrl
{
    public static function current(): string
    {
        $configured = (string) config('portal.url');

        // Outside a web request (a queue worker, an artisan command) there is no browser, so no Origin either,
        // and this falls through to the configured address below.
        if (! app()->bound('request')) {
            return $configured;
        }

        $request = request();
        $candidate = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $parts = $candidate ? parse_url($candidate) : false;

        if (! $parts || empty($parts['scheme']) || empty($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return $configured;
        }

        if (! self::trusts($parts['host'], $configured)) {
            return $configured;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** Local development, quick tunnels, the configured portal's own host, and anything in PORTAL_ALLOWED_HOSTS. */
    private static function trusts(string $host, string $configured): bool
    {
        $host = strtolower($host);

        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }

        $allowed = array_filter(array_map('trim', array_merge(
            [parse_url($configured, PHP_URL_HOST) ?: ''],
            (array) config('portal.allowed_hosts'),
        )));

        foreach ($allowed as $pattern) {
            $pattern = strtolower($pattern);

            // ".example.com" (or "*.example.com") covers the domain and every subdomain of it.
            if (str_starts_with($pattern, '*.')) {
                $pattern = substr($pattern, 1);
            }

            if (str_starts_with($pattern, '.')) {
                if ($host === ltrim($pattern, '.') || str_ends_with($host, $pattern)) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
