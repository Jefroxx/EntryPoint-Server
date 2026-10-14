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

        // On a temporary password, only changing it (and the staff screens' own "who am I" call) is allowed.
        if ($user && $user->mustChangePassword && ! $request->is('api/student/password', 'api/librarian/settings/password', 'api/librarian/me')) {
            return response()->json([
                'message' => 'Set a new password before continuing.',
                'code'    => 'password_change_required',
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
