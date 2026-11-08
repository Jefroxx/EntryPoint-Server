<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A normal sign-in has every ability (`*`). A phone that scanned the desk's QR gets a limited one that may only send
 * barcodes, so every ordinary route turns it away. Without this check the limited token would still pass, because most
 * routes only ask "is someone signed in?" and never look at what the token is allowed to do.
 */
class RejectScopedTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can('*')) {
            return response()->json(['message' => 'This sign-in can only be used to scan.'], 403);
        }

        return $next($request);
    }
}
