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
        $user = $request->user();

        // Administrators have their own side (/api/admin). The librarian side is closed to them, apart from the few
        // routes their own screens need: who they are, announcements, and their own account.
        if ($user && $this->permissions->roleOf($user) === StaffPermissionService::ADMIN
            && ! $request->is('api/librarian/me', 'api/librarian/announcements', 'api/librarian/settings/account', 'api/librarian/settings/password')) {
            return response()->json([
                'message' => 'Administrators do not use the librarian side.',
            ], 403);
        }

        $needed = $this->permissions->requiredFor($request);

        if ($needed !== null && ! $this->permissions->allows($request->user(), $needed)) {
            return response()->json([
                'message' => 'Your role does not include this. Ask an administrator if you need access.',
            ], 403);
        }

        return $next($request);
    }
}
