<?php

namespace App\Http\Middleware;

use App\Services\StaffPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a staff account to the areas its role allows (see config/permissions.php). Runs after `librarian`, on the
 * whole /api/librarian group, so a new route is covered as soon as its section has a rule.
 */
class EnforceStaffPermission
{
    public function __construct(private StaffPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $needed = $this->permissions->requiredFor($request);

        if ($needed !== null && ! $this->permissions->allows($request->user(), $needed)) {
            return response()->json([
                'message' => 'Your role does not include this. Ask an administrator if you need access.',
            ], 403);
        }

        return $next($request);
    }
}
