<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStudent
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

        if (! $user || $user->userType !== 'student') {
            return response()->json([
                'message' => 'This action is restricted to students only.',
            ], 403);
        }

        if (! $user->student || ! $user->student->isApproved()) {
            return response()->json([
                'message' => 'Your account must be approved before performing this action.',
            ], 403);
        }

        return $next($request);
    }
}
