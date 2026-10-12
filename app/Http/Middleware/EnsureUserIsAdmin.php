<?php

namespace App\Http\Middleware;

use App\Services\StaffPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function __construct(private StaffPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->userType !== 'librarian' || $this->permissions->roleOf($user) !== StaffPermissionService::ADMIN) {
            return response()->json([
                'message' => 'This action is restricted to administrators only.',
            ], 403);
        }

        return $next($request);
    }
}
