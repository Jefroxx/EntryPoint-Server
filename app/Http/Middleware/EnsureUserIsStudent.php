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
