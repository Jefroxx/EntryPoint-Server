<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsLibrarian
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->disabledAt) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'This account has been disabled. Contact the library administrator.',
            ], 403);
        }

        if (! $user || $user->userType !== 'librarian') {
            return response()->json([
                'message' => 'This action is restricted to librarians only.',
            ], 403);
        }

        return $next($request);
    }
}
