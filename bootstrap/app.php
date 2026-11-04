<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // There is no web login page to send a signed-out API caller to (the Nuxt app owns sign-in), so don't try.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => $request->is('api/*') ? null : '/login');

        // Behind the Nuxt proxy / Cloudflare tunnel, so trust X-Forwarded-* for https URLs.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'librarian'        => \App\Http\Middleware\EnsureUserIsLibrarian::class,
            'student'          => \App\Http\Middleware\EnsureUserIsStudent::class,
            // Staff roles: `admin` is the admin side; `staff.permission` keeps librarians and assistants to their areas.
            'admin'            => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'staff.permission' => \App\Http\Middleware\EnforceStaffPermission::class,
            'engagement'       => \App\Http\Middleware\EnsureStudentTakesPartInEngagement::class,
            'audit'            => \App\Http\Middleware\LogStaffActivity::class,
            // A phone paired to the desk holds a limited token: `full.token` turns it away from every ordinary route,
            // `abilities` lets it into its own.
            'full.token'       => \App\Http\Middleware\RejectScopedTokens::class,
            'abilities'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in JSON. A request without an Accept header used to be redirected to a "login" route
        // that doesn't exist (a 500); now it is a plain 401.
        $exceptions->shouldRenderJsonWhen(fn (\Illuminate\Http\Request $request, \Throwable $e) => $request->is('api/*') || $request->expectsJson());

        // A request with a dead token is a 401. When the token died because the account signed in somewhere else (the
        // token's id is the part of the bearer before the "|"), say so, so the app can tell the person why.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $id = strtok((string) $request->bearerToken(), '|');
            $replaced = $id !== false && ctype_digit($id)
                && \Illuminate\Support\Facades\Cache::has(\App\Services\AuthService::REPLACED_TOKEN_PREFIX.$id);

            return response()->json([
                'message' => $replaced ? 'You were signed out because this account was signed in somewhere else.' : $e->getMessage(),
                'reason'  => $replaced ? 'signed_in_elsewhere' : 'unauthenticated',
            ], 401);
        });
    })->create();
